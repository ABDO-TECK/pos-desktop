declare global {
  interface AppSettings {
    store_name?: string;
    tax_rate?: string;
    prevent_negative_stock?: string;
    currency?: string;
    receipt_header?: string;
    receipt_footer?: string;
    [key: string]: string | undefined;
  }

  interface ReleaseInfo {
    title: string | null;
    tag_name: string | null;
    changelog: { version?: string; date?: string; changes?: string[] }[] | string[];
    released_at: string | null;
    files_count: number | null;
    release_url: string | null;
    download_url: string | null;
    full_package_url?: string | null;
  }

  interface UpdateStateData {
    state: 'pending' | 'downloading' | 'verifying' | 'backing_up' | 'applying' | 'migrating' | 'completed' | 'failed' | 'rolled_back' | 'idle';
    started_at?: string;
    updated_at?: string;
    error?: string | null;
    applied_files?: string[];
    backup_snapshot?: string | null;
  }

  interface UpdateStatusData {
    current_version: string;
    latest_version: string | null;
    update_available: boolean;
    type: 'bootstrap' | 'delta' | 'full';
    bootstrap_required?: boolean;
    channel?: 'stable' | 'beta' | 'rc';
    available_channels?: string[];
    device_id?: string;
    release_info: ReleaseInfo;
    update_state: UpdateStateData | null;
    interrupted_update?: {
      interrupted: boolean;
      state: string | null;
      snapshot_path: string | null;
      message: string | null;
      details: unknown;
    };
  }


  interface UpdateCheckResult {
    current_version: string | null;
    latest_version: string | null;
    has_update: boolean;
    released_at: string | null;
    changelog: { version: string; date: string; changes: string[] }[];
    requires_npm_install?: boolean;
    status?: string;
    requires_desktop_handoff?: boolean;
    handoff_version?: string;
    message?: string;
    checkedUrl?: string;
    errorCode?: string | null;
    details?: string | null;
    updates_disabled?: boolean;
    updates_unreachable?: boolean;
    update_type?: 'delta' | 'full';
    is_delta?: boolean;
    files_count?: number | null;
    source?: string;
    release_tag?: string;
    manifest_url?: string | null;
    signature_url?: string | null;
    delta_url?: string | null;
    fallback_reason?: string | null;
    bootstrap_required?: boolean;
    client_channel?: 'stable' | 'beta' | 'rc';
    release_channel?: 'stable' | 'beta' | 'rc';
    rollout_percentage?: number;
    device_bucket?: number;
    in_rollout_group?: boolean;
  }


  interface UpdateApplyResult {
    message?: string;
    latest_version?: string;
    update_type?: string;
    applied_files?: string[];
    logs?: string[];
    job_id?: number;
    status?: string;
    requires_desktop_handoff?: boolean;
    handoff_version?: string;
  }

  interface UpdateHistoryRecord {
    id: number;
    from_version: string;
    to_version: string;
    type: 'delta' | 'full';
    source: string | null;
    release_tag: string | null;
    status: 'success' | 'failed' | 'rolled_back';
    files_count: number;
    backup_path: string | null;
    download_url: string | null;
    error_message: string | null;
    created_at: string;
  }

  interface UpdateSnapshot {
    snapshot_name: string;
    snapshot_path: string;
    from_version: string;
    to_version: string;
    timestamp: string;
    files_count: number;
    has_db_backup: boolean;
    db_backup_path: string | null;
  }

  interface UpdateRollbackResult {
    ok: boolean;
    snapshot?: string;
    logs?: string[];
    error?: string | null;
  }

  interface UpdateJobResult {
    id: number;
    job_name: 'apply_update';
    status: 'pending' | 'processing' | 'completed' | 'failed';
    attempts: number;
    max_attempts: number;
    last_error: string | null;
    failure_code: number | null;
    created_at: string;
    completed_at: string | null;
  }

  interface FleetAlert {
    severity: 'critical' | 'warning' | 'info';
    code: string;
    title: string;
    message: string;
  }

  interface FleetHealthStats {
    total_events: number;
    successful: number;
    failed: number;
    rollbacks: number;
    success_rate: number;
    failure_rate: number;
  }

  interface FleetStatsData {
    ok: boolean;
    total_devices: number;
    version_distribution: Record<string, number>;
    channel_distribution: { stable: number; beta: number; rc: number };
    update_health: FleetHealthStats;
    alerts: FleetAlert[];
    last_synced_at: string;
  }

  interface FleetDeviceRecord {
    device_id: string;
    current_version: string;
    channel: string;
    last_event: string;
    last_event_success: number | boolean;
    last_seen_at: string;
    total_events: number;
  }

  interface TelemetryEventRecord {
    id: number;
    device_id: string;
    current_version: string;
    target_version: string | null;
    channel: string;
    event_type: string;
    success: number | boolean;
    error_code: string | null;
    duration_ms: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
  }

  interface DeviceDetailsData {
    device_id: string;
    current_version: string;
    channel: string;
    last_seen_at: string;
    events: TelemetryEventRecord[];
  }

  interface RecoveryDiagnosisData {
    status: string;
    state: string;
    problem_detected: boolean;
    recommended_action: 'none' | 'retry_download' | 'retry_verification' | 'resume' | 'rollback' | 'clear' | 'escalate';
    message: string;
    details: Record<string, unknown>;
    is_locked: boolean;
    auto_recovery_enabled: boolean;
  }

  interface RecoveryActionResult {
    ok: boolean;
    action: string;
    message?: string;
    error?: string;
    snapshot?: string;
    attempts?: number;
    logs?: string[];
  }

  interface RecoveryAuditEntry {
    id: string;
    timestamp: string;
    previous_state: string;
    detected_problem: string;
    selected_action: string;
    success: boolean;
    details: Record<string, unknown>;
  }

  interface RecoveryHealthCheckResult {
    healthy: boolean;
    auto_rollback: boolean;
    checks: {
      db_connection: boolean;
      core_tables: boolean;
      version_file: boolean;
      backend_entry: boolean;
    };
    errors: string[];
    message: string;
  }
}

export {};
