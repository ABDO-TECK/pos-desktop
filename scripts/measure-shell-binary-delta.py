"""Measure a reconstructible hybrid ZIP over accepted, packaged Windows bytes."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile


LAB, ZSTD, OPENSSL = map(Path, sys.argv[1:4])
TOOL_ARCHIVE_SHA256 = sys.argv[4]
EVIDENCE = LAB / "evidence"
BENCHMARK_FILES = (
    "POS Desktop.exe",
    "resources/app.asar",
    "resources/app.asar.unpacked/backend/backend.phar",
)


def sha256(file):
    with file.open("rb") as data:
        return hashlib.file_digest(data, "sha256").hexdigest()


def verify_bytes(file, entry):
    if file.stat().st_size != entry["size"] or sha256(file) != entry["sha256"]:
        raise ValueError(f"Byte reconstruction differs from signed inventory: {file}")


def run(*args):
    return subprocess.run([str(arg) for arg in args], check=True, timeout=300)


def write_json(file, data):
    file.write_bytes((json.dumps(data, indent=2, ensure_ascii=False) + "\n").encode("utf-8"))


def summarize_zip(bundle, archive_file, manifest_file, signature_file):
    payload_bytes = sum(entry.compress_size for entry in bundle.infolist())
    zip_bytes = archive_file.stat().st_size
    manifest_bytes = manifest_file.stat().st_size
    signature_bytes = signature_file.stat().st_size
    return {
        "zip_sha256": sha256(archive_file),
        "zip_bytes": zip_bytes,
        "zip_overhead_bytes": zip_bytes - payload_bytes,
        "compressed_entries_bytes": payload_bytes,
        "manifest_bytes": manifest_bytes,
        "manifest_sha256": sha256(manifest_file),
        "signature_bytes": signature_bytes,
        "signature_sha256": sha256(signature_file),
        "download_bytes": zip_bytes + manifest_bytes + signature_bytes,
    }


def sign(scratch, raw_manifest, signature):
    private = scratch / "benchmark-private.pem"
    public = scratch / "benchmark-public.pem"
    binary = scratch / "benchmark-signature.bin"
    run(OPENSSL, "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out", private)
    try:
        run(OPENSSL, "pkey", "-in", private, "-pubout", "-out", public)
        run(OPENSSL, "dgst", "-sha256", "-sign", private, "-out", binary, raw_manifest)
        run(OPENSSL, "dgst", "-sha256", "-verify", public, "-signature", binary, raw_manifest)
        signature.write_bytes(base64.b64encode(binary.read_bytes()) + b"\n")
    finally:
        private.unlink(missing_ok=True)


def measure(source, target, release, scratch, expected_zip_hash=None):
    original_manifest = release / "shell-manifest.json"
    original_signature = release / "shell-manifest.sig"
    original_zip = release / "shell-delta.zip"
    manifest = json.loads(original_manifest.read_bytes())
    verify_bytes(original_zip, manifest["package"])
    if expected_zip_hash and sha256(original_zip) != expected_zip_hash:
        raise ValueError("The whole-file comparison does not match accepted ZIP bytes.")

    source_files = manifest["source_inventory"]["files"]
    target_files = manifest["target_inventory"]["files"]
    changed = {record["path"]: record for record in manifest["files"]}
    retained = target_files.keys() - changed.keys()
    deleted = set(manifest["deleted_files"])
    if not set(BENCHMARK_FILES).issubset(changed):
        raise ValueError("The signed comparison is missing one or more requested changed files.")
    if deleted != source_files.keys() - target_files.keys():
        raise ValueError("Signed deleted files do not match the difference in inventories.")
    for name, entry in source_files.items():
        verify_bytes(source / name, entry)
    for name, entry in target_files.items():
        verify_bytes(target / name, entry)
    for name in retained:
        if source_files[name] != target_files[name]:
            raise ValueError(f"Changed file absent from the signed archive: {name}")

    payload = scratch / "hybrid.zip"
    meta = scratch / "hybrid-manifest.json"
    signature = scratch / "hybrid-manifest.sig"
    extracted = scratch / "extracted"
    reconstructed = scratch / "reconstructed"
    decoded_patch = scratch / "decoded-patch"
    metrics = {}
    operations = []
    with zipfile.ZipFile(original_zip) as whole, zipfile.ZipFile(payload, "x", zipfile.ZIP_DEFLATED, compresslevel=6) as hybrid:
        if set(whole.namelist()) != changed.keys() or len(whole.namelist()) != len(changed):
            raise ValueError("Original ZIP entry names do not match the signed change list.")
        whole_totals = summarize_zip(whole, original_zip, original_manifest, original_signature)

        for name, file in changed.items():
            entry = whole.getinfo(name)
            with whole.open(entry) as src, extracted.open("wb") as dest:
                shutil.copyfileobj(src, dest)
            verify_bytes(extracted, file)
            encoding = "whole-file"
            input_file = extracted
            if name in BENCHMARK_FILES:
                original = source / name
                updated = target / name
                patch = scratch / "patch.zst"
                reference = f"--patch-from={original}"
                run(ZSTD, "-q", "-f", "-10", "--single-thread", "--memory=512MB", reference, updated, "-o", patch)
                run(ZSTD, "-q", "-f", "-d", "--memory=512MB", reference, patch, "-o", reconstructed)
                verify_bytes(reconstructed, file)
                patch_bytes = patch.stat().st_size
                if patch_bytes < entry.compress_size:
                    encoding = "zstd-patch-from"
                    input_file = patch
                metrics[name] = {
                    "source_sha256": source_files[name]["sha256"],
                    "target_sha256": file["sha256"],
                    "source_bytes": source_files[name]["size"],
                    "target_bytes": file["size"],
                    "whole_file_compressed_entry_bytes": entry.compress_size,
                    "binary_patch_bytes": patch_bytes,
                    "entry_byte_savings_before_metadata": entry.compress_size - patch_bytes,
                    "reconstructed_patch_sha256": sha256(reconstructed),
                    "selected_encoding": encoding,
                }
            method = zipfile.ZIP_STORED if encoding == "zstd-patch-from" else zipfile.ZIP_DEFLATED
            hybrid.write(input_file, name, compress_type=method)
            operation = {**file, "encoding": encoding}
            if encoding == "zstd-patch-from":
                operation["source_sha256"] = source_files[name]["sha256"]
            operations.append(operation)
    descriptor = {
        "benchmark_format": 1,
        "source_inventory": manifest["source_inventory"],
        "target_inventory": manifest["target_inventory"],
        "source_inventory_sha256": manifest["source_inventory_sha256"],
        "target_inventory_sha256": manifest["target_inventory_sha256"],
        "package": {"asset": "hybrid.zip", "size": payload.stat().st_size, "sha256": sha256(payload)},
        "files": operations,
        "deleted_files": manifest["deleted_files"],
    }
    write_json(meta, descriptor)
    sign(scratch, meta, signature)

    with zipfile.ZipFile(payload) as hybrid:
        for operation in descriptor["files"]:
            name = operation["path"]
            entry = hybrid.getinfo(name)
            with hybrid.open(entry) as src, decoded_patch.open("wb") as dest:
                shutil.copyfileobj(src, dest)
            if operation["encoding"] == "zstd-patch-from":
                original = source / name
                if sha256(original) != operation["source_sha256"]:
                    raise ValueError(f"Incorrect binary patch source for {name}")
                run(ZSTD, "-q", "-f", "-d", "--memory=512MB", f"--patch-from={original}", decoded_patch, "-o", reconstructed)
                verify_bytes(reconstructed, operation)
            else:
                verify_bytes(decoded_patch, operation)
        hybrid_totals = summarize_zip(hybrid, payload, meta, signature)

    whole_bytes = whole_totals["download_bytes"]
    hybrid_bytes = hybrid_totals["download_bytes"]
    return {
        "source_version": manifest["from_version"],
        "target_version": manifest["version"],
        "source_inventory_sha256": manifest["source_inventory_sha256"],
        "target_inventory_sha256": manifest["target_inventory_sha256"],
        "changed_files_reconstructed": len(changed),
        "retained_files_verified": len(retained),
        "deleted_files_verified": len(deleted),
        "files": metrics,
        "signed_whole_file": whole_totals,
        "signed_hybrid_benchmark": hybrid_totals,
        "download_savings_bytes": whole_bytes - hybrid_bytes,
        "download_savings_percent": round(100 * (whole_bytes - hybrid_bytes) / whole_bytes, 2),
    }


def main():
    if sys.platform != "win32" or os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("RUNNER_ENVIRONMENT") != "github-hosted":
        raise RuntimeError("Benchmark only on GitHub-hosted Windows Actions.")
    commit_proof = json.loads((EVIDENCE / "commit.json").read_bytes())
    rollback_proof = json.loads((EVIDENCE / "rollback.json").read_bytes())
    if commit_proof.get("passed") is not True or rollback_proof.get("passed") is not True:
        raise ValueError("The commit and automatic rollback proofs must pass before measurement.")
    version = subprocess.check_output([str(ZSTD), "--version"], text=True, timeout=10).strip()
    if "v1.5.7" not in version:
        raise ValueError(f"Unexpected benchmark tool version: {version}")
    target = LAB / "target" / "win-unpacked"
    with tempfile.TemporaryDirectory(dir=LAB, prefix="measure-accepted-") as scratch:
        accepted = measure(LAB / "source" / "installed-payload", target, LAB / "target" / "release",
                           Path(scratch), commit_proof["zip_sha256"])
    with tempfile.TemporaryDirectory(dir=LAB, prefix="measure-published-") as scratch:
        historical = measure(LAB.parent / "setup13-shell" / "source", target, LAB / "historical-release", Path(scratch))
    write_json(EVIDENCE / "binary-delta-measurement.json", {
        "scope": "Measurement only; no benchmark decoder or format is shipped by the updater.",
        "tool": {"version": version, "archive_sha256": TOOL_ARCHIVE_SHA256, "compression_level": 10, "memory_ceiling_mb": 512},
        "accepted_pair": accepted,
        "published_0_0_13_to_unpublished_0_0_14": historical,
    })
    print("Accepted pair:", accepted["signed_whole_file"]["download_bytes"], "whole-file bytes,",
          accepted["signed_hybrid_benchmark"]["download_bytes"], "hybrid bytes")
    print("Published baseline pair:", historical["signed_whole_file"]["download_bytes"], "whole-file bytes,",
          historical["signed_hybrid_benchmark"]["download_bytes"], "hybrid bytes")


if __name__ == "__main__":
    main()
