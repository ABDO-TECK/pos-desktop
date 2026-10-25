@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Repair-POS.ps1" %*
exit /b %ERRORLEVEL%
