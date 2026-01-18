; NSIS 安装器配置文件
; 注册自定义 URL 协议

!macro customInstall
  ; 注册 mkmusic:// 协议
  WriteRegStr HKCR "mkmusic" "" "URL:MK Music Protocol"
  WriteRegStr HKCR "mkmusic" "URL Protocol" ""
  WriteRegStr HKCR "mkmusic\DefaultIcon" "" "$INSTDIR\${APP_EXECUTABLE_FILENAME}"
  WriteRegStr HKCR "mkmusic\shell" "" ""
  WriteRegStr HKCR "mkmusic\shell\open" "" ""
  WriteRegStr HKCR "mkmusic\shell\open\command" "" '"$INSTDIR\${APP_EXECUTABLE_FILENAME}" "%1"'
!macroend

!macro customUnInstall
  ; 卸载时删除协议注册
  DeleteRegKey HKCR "mkmusic"
!macroend
