构建应用图标说明
==================

在构建应用之前，请准备以下图标文件：

Windows 平台
-----------
文件: build/icon.ico
尺寸: 256x256 像素
格式: ICO 格式

macOS 平台
----------
文件: build/icon.icns
格式: ICNS 格式（包含多种尺寸）

Linux 平台
----------
目录: build/icons/
需要以下尺寸的 PNG 图标：
- 16x16.png
- 32x32.png
- 48x48.png
- 64x64.png
- 128x128.png
- 256x256.png
- 512x512.png
- 1024x1024.png

图标转换工具
-----------
1. PNG 转 ICO: https://convertico.com/
2. PNG 转 ICNS:
   - macOS: 使用 iconutil 工具
   - 在线工具: https://cloudconvert.com/png-to-icns

临时图标
-------
如果暂时没有图标文件，可以：
1. 使用项目中的 images/avatar.png 或其他图片作为基础
2. 使用在线工具转换为所需格式
3. 或者先跳过图标设置，使用 Electron 默认图标

注意：构建时如果缺少图标文件，会使用默认图标。
