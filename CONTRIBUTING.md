# 贡献指南

感谢关注 **gputemp**（Unraid GPU 温度监控插件）。以下是参与开发需要遵守的约定，
流程刻意保持轻量。

## 开发环境

- Windows + PowerShell ≥ 5.1（构建脚本以此为准）；
- 可选：本机 PHP CLI（`validate.ps1` 检测到 `php` 时会对全部 `.php` / `.page`
  执行 `php -l` 语法检查）；
- 可选：WSL（bsdtar 缺 xz 支持时的备选打包路径，见下方"构建"步骤）。
  在 WSL 中执行：

  ```bash
  cd /mnt/<盘符>/<仓库路径>
  tar -cJf dist/gputemp-<版本>-x86_64-1.txz -C archive usr
  sha256sum dist/gputemp-<版本>-x86_64-1.txz   # 手工回填模板 &sha256;
  ```

## 行尾与编码（硬性要求）

- 全部文本文件必须为 **LF 行尾、UTF-8 无 BOM**。Unraid 侧的 shell / PHP 对
  CRLF 敏感，`.plg` 脚本段混入 CR 会直接导致安装失败；
- 仓库根目录的 `.gitattributes` 已声明 `* text=auto eol=lf`，但不依赖它兜底：
  提交前请运行换行归一脚本确认：

  ```powershell
  powershell -ExecutionPolicy Bypass -File .\tools\normalize-eol.ps1
  ```

## 提交流程

1. **改码**：插件源码全部位于 `archive/usr/local/emhttp/plugins/gputemp/`
   （该目录即 txz 包内容，路径镜像运行目录）；
2. **门禁**：运行静态门禁，全绿才可继续：

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\validate.ps1
   ```

3. **版本号**：只要包内容有任何变更，**必须同步递增版本号**（`YYYY.MM.DD`
   风格，同日多次发布递增末段或加修订号）。版本号同时出现在四处，
   须保持一致：`gputemp.plg.tmpl`（`&version;` 实体）、
   `archive/…/include/helper.php`（`GPUTEMP_VERSION` 常量及各文件头部
   版本注释）、`build.ps1`（`$version`）、`README.md`（版本徽章注释与
   包名引用）；
4. **构建**：

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\build.ps1
   ```

   产物为 `dist/gputemp-<版本>-x86_64-1.txz` 与回填了 SHA256 的
   `dist/gputemp.plg`；
5. **一并提交** `dist/` 产物：本仓库无公网托管，用户依赖仓库内现成的
   `.plg` + txz 做离线安装，源码与产物必须同 commit 保持哈希一致。

## ⚠️ 版本号必须递增的原因（真实踩坑记录）

Unraid 插件管理器对**同名同版本**包的处理是：若目标 txz 已存在且
SHA256 与 `.plg` 声明一致，下载段整体跳过并直接执行
`upgradepkg --install-new`；而 `upgradepkg --install-new` 遇到**已安装同版本**
的包时会**静默跳过、不做任何文件替换**。也就是说：

> 改了源码却不递增版本号，用户重装时会得到"安装成功"的假象，
> 实际运行的仍是旧包内容，且没有任何报错。

因此：**内容变更 ⇒ 版本号必须变更**，这是本仓库不可妥协的发布纪律。
（版本号未变但确需强制重装时，参见 README 第 4 节的 `installplugin … forced`
流程，仅作为应急手段。）

## 其他约定

- 不引入 daemon / cron（请求驱动 + tmpfs 缓存是显式设计需求）；
- 配置读写只经官方 `parse_plugin_cfg()` / `file_put_contents_atomic()`，
  禁止直接 `parse_ini_file()` / `file_put_contents()` 操作用户 cfg；
- remove 段禁止任何 `/boot` 删除语句（卸载保留配置为显式需求 FR-6.2）；
- 以上静态可判定项均已纳入 `validate.ps1` 门禁，触发即阻断构建。
