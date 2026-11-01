@ECHO OFF
REM Windows launcher for Laravel Project Inspector.
SETLOCAL
SET "LARAINSPECT_BIN=%~dp0larainspect"
php "%LARAINSPECT_BIN%" %*
EXIT /B %ERRORLEVEL%
