; build/installer.nsh
; Custom NSIS installer logic for POS Desktop: Microsoft Visual C++ 2015-2022 x64 Redistributable prerequisite

!include LogicLib.nsh

; Pinned VC++ 2015-2022 x64 Redistributable Version: 14.44.35211.0
!define PINNED_VC_MAJOR 14
!define PINNED_VC_MINOR 44
!define PINNED_VC_BLD   35211
!define PINNED_VC_RBLD  0

; Macro: CheckVcRedistCompatibility
; Reads official Microsoft Visual C++ 2015-2022 x64 runtime registration from 64-bit registry.
; Outputs 1 to OUTPUT_VAR if runtime is installed and version >= 14.44.35211.0, otherwise 0.
!macro CheckVcRedistCompatibility OUTPUT_VAR
  Push $R1
  Push $R2
  Push $R3
  Push $R4
  Push $R5

  StrCpy ${OUTPUT_VAR} 0

  SetRegView 64
  ClearErrors
  ReadRegDWORD $R1 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Installed"
  ${If} ${Errors}
    StrCpy $R1 0
  ${EndIf}

  ReadRegDWORD $R2 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Major"
  ${If} ${Errors}
    StrCpy $R2 0
  ${EndIf}

  ReadRegDWORD $R3 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Minor"
  ${If} ${Errors}
    StrCpy $R3 0
  ${EndIf}

  ReadRegDWORD $R4 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Bld"
  ${If} ${Errors}
    StrCpy $R4 0
  ${EndIf}

  ReadRegDWORD $R5 HKLM "SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64" "Rbld"
  ${If} ${Errors}
    StrCpy $R5 0
  ${EndIf}
  SetRegView lastused

  DetailPrint "Detected VC++ x64 runtime: Installed=$R1, Version=$R2.$R3.$R4.$R5"

  ${If} $R1 == 1
    ${If} $R2 > ${PINNED_VC_MAJOR}
      StrCpy ${OUTPUT_VAR} 1
    ${ElseIf} $R2 == ${PINNED_VC_MAJOR}
      ${If} $R3 > ${PINNED_VC_MINOR}
        StrCpy ${OUTPUT_VAR} 1
      ${ElseIf} $R3 == ${PINNED_VC_MINOR}
        ${If} $R4 > ${PINNED_VC_BLD}
          StrCpy ${OUTPUT_VAR} 1
        ${ElseIf} $R4 == ${PINNED_VC_BLD}
          ${If} $R5 >= ${PINNED_VC_RBLD}
            StrCpy ${OUTPUT_VAR} 1
          ${EndIf}
        ${EndIf}
      ${EndIf}
    ${EndIf}
  ${EndIf}

  Pop $R5
  Pop $R4
  Pop $R3
  Pop $R2
  Pop $R1
!macroend

!macro customInstall
  DetailPrint "Checking Microsoft Visual C++ 2015-2022 Redistributable (x64)..."
  ; $8 tracks whether prerequisite installation requires a system restart (exit code 3010)
  StrCpy $8 0

  !insertmacro CheckVcRedistCompatibility $0

  ${If} $0 == 1
    DetailPrint "Compatible Microsoft Visual C++ 2015-2022 x64 Redistributable is already installed."
  ${Else}
    DetailPrint "Installing Microsoft Visual C++ 2015-2022 Redistributable (x64)..."
    InitPluginsDir

    !ifdef BUILD_RESOURCES_DIR
      File /oname=$PLUGINSDIR\vc_redist.x64.exe "${BUILD_RESOURCES_DIR}\prerequisites\vc_redist.x64.exe"
    !else
      File /oname=$PLUGINSDIR\vc_redist.x64.exe "prerequisites\vc_redist.x64.exe"
    !endif

    ExecWait '"$PLUGINSDIR\vc_redist.x64.exe" /install /passive /norestart' $1
    DetailPrint "VC++ Redistributable installer exit code: $1"
    Delete "$PLUGINSDIR\vc_redist.x64.exe"

    ${If} $1 == 0
      DetailPrint "VC++ Redistributable installed successfully."
    ${ElseIf} $1 == 1638
      ; 1638 = newer version already installed. Post-verify registry state.
      DetailPrint "VC++ Redistributable returned 1638 (another version installed). Verifying registry state..."
      !insertmacro CheckVcRedistCompatibility $2
      ${If} $2 == 1
        DetailPrint "Compatible VC++ runtime confirmed in registry."
      ${Else}
        MessageBox MB_ICONSTOP "Microsoft Visual C++ Redistributable returned code 1638 but a compatible x64 runtime could not be verified in the Windows registry. Setup cannot continue."
        Abort
      ${EndIf}
    ${ElseIf} $1 == 3010
      DetailPrint "VC++ Redistributable requires a system restart (code 3010)."
      StrCpy $8 1
    ${Else}
      MessageBox MB_ICONSTOP "Failed to install Microsoft Visual C++ 2015-2022 Redistributable (x64). Exit code: $1. POS requires this runtime component to start."
      Abort
    ${EndIf}
  ${EndIf}

  ; MANDATORY POST-CONDITION: Verify packaged PHP runtime binary before allowing application launch
  DetailPrint "Verifying packaged PHP runtime..."
  StrCpy $3 "$INSTDIR\resources\portable\php\php.exe"
  ${If} ${FileExists} $3
    ExecWait '"$3" -v' $4
    DetailPrint "Packaged PHP verification exit code: $4"
    ${If} $4 != 0
      ${If} $8 == 1
        MessageBox MB_ICONSTOP "Microsoft Visual C++ Redistributable was installed, but Windows must be restarted before POS can run (PHP loader exit code: $4). Please restart your computer and launch POS."
      ${Else}
        MessageBox MB_ICONSTOP "Packaged PHP runtime verification failed (exit code $4). Required Windows runtime DLLs could not be loaded. Setup cannot complete."
      ${EndIf}
      Abort
    ${EndIf}
    DetailPrint "Packaged PHP runtime verified successfully."
  ${Else}
    DetailPrint "Warning: Packaged PHP executable not found at $3"
  ${EndIf}
!macroend
