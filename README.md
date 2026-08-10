# gputemp — Unraid GPU 温度监控插件

<!-- 徽章位：本仓库暂无公网托管，待上线 GitHub Pages / 托管后在此启用 shields.io 徽章，
     建议位：版本号 | MIT 许可证 | Unraid ≥ 7.2.0-beta，例如：
     ![version](https://img.shields.io/badge/version-2026.08.10-blue)
     ![license](https://img.shields.io/badge/license-MIT-green)
-->

> 在 Unraid Dashboard 上以一张"主板风格"磁贴实时展示 GPU 温度与超温分级的轻量插件——无守护进程、零公网依赖、离线可装。

> 插件名：`gputemp`　|　版本：`2026.08.10`　|　包名：`gputemp-2026.08.10-x86_64-1.txz`
> 依据《GPU 温度监控插件 软件需求规格说明书（SRS）》v1.6（61 条需求 / 50 条验收准则）开发。

---

## 目录

1. [项目简介](#1-项目简介)
2. [项目结构](#2-项目结构)
3. [安装](#3-安装)
4. [升级](#4-升级)
5. [标准卸载（保留配置）](#5-标准卸载保留配置)
6. [彻底清除](#6-彻底清除)
7. [配置说明](#7-配置说明)
8. [许可证](#8-许可证)

---

## 1. 项目简介

**gputemp** 是一个面向 Unraid 的轻量级 GPU 温度监控插件：在 Dashboard 上以一张
"主板风格"磁贴（tile）实时展示每张受支持 GPU 的温度（整数 ℃）与显存占用
（已用 / 总量，GB），并提供警告 / 临界两级超温视觉提示（仅磁贴内变色，
不接入 Unraid 通知系统）。

### 功能定位

- **独显全监控 + AMD APU 核显**：全部独立显卡（NVIDIA / AMD / Intel）均予监控；
  集成显卡仅监控 AMD APU 核显（Intel 核显不在显示范围内）。支持经
  `ENABLED_GPUS` 白名单按 PCI 地址二次过滤。
- **无守护进程、请求驱动 + tmpfs 缓存**：不注册任何 daemon / cron。前端 JS 以
  锚点自校正调度轮询只读 GET JSON 端点；端点内完成设备枚举、采集与降级判定；
  采集结果缓存于 tmpfs（`/var/local/emhttp/plugins/gputemp/`），多个浏览器标签页
  并发请求经文件锁合并为单一采集进程，TTL 内直接复用缓存。
- **配置托管平台官方函数**：配置读写完全经由 Unraid 官方 `Wrappers.php` 的
  `parse_plugin_cfg()` / `file_put_contents_atomic()`，默认值由随包安装的
  `default.cfg` 合并回退。

### 支持平台

| 项目 | 说明 |
|---|---|
| 最低版本 | Unraid **7.2.0-beta**（`.plg` 声明 `min="7.2.0-beta"`，pre-install 脚本复核） |
| 目标版本 | Unraid **7.3.2** |
| 架构 | x86_64 |

### 支持 GPU 厂商与数据源

| 厂商 | 温度数据源 | 显存数据源 |
|---|---|---|
| NVIDIA | `nvidia-smi`（单条命令查询全部卡） | 同条命令的 `memory.used` / `memory.total`（MiB） |
| AMD | hwmon `temp1_input`（m℃ ÷1000，amdgpu） | sysfs `mem_info_vram_used` / `mem_info_vram_total`（字节；APU 无本地 VRAM → 显示 N/A） |
| Intel | hwmon `temp1_input`（i915 / xe） | sysfs `lmem_used_bytes` / `lmem_total_bytes` 探测式读取（主线内核/xe 未提供该节点时显示 N/A） |

hwmon 设备按 `name` 属性匹配并经 `device` 符号链接解析 PCI 地址绑定，
源码中不存在硬编码的 `hwmon<数字>` 路径（编号漂移容错，AC-FUNC-03）。

---

## 2. 项目结构

```text
.
├── archive/usr/local/emhttp/plugins/gputemp/   插件源码（= txz 包内容，路径镜像运行目录）
│   ├── api/gputemp.php          只读 GET JSON 采集端点（枚举 + 采集 + 降级判定 + tmpfs 缓存/文件锁）
│   ├── include/collect.php      GPU 枚举与温度/显存采集（nvidia-smi / hwmon / sysfs）
│   ├── include/detect.php       数据源探测与可用性判定
│   ├── include/helper.php       通用辅助（gputemp_cfg() 配置读取、路径与日志封装）
│   ├── css/gputemp.css          磁贴样式（温度分级变色、响应式断点）
│   ├── js/gputemp.js            前端轮询（锚点自校正调度、渲染、[不可用] 标注）
│   ├── images/gputemp.png       Plugins 页图标
│   ├── gputemp.tile.page        Dashboard 磁贴注入页（两行布局，可见标题由首行渲染）
│   ├── gputemp.settings.page    设置页（含"清除配置"按钮）
│   ├── save.php                 配置保存（7 项校验 + CSRF 前置于写入）
│   └── default.cfg              6 键默认配置（用户配置缺键时回退）
├── dist/                        构建产物【有意入库的离线交付物】：gputemp.plg + 配套 txz
├── tools/make-icon.ps1          图标生成辅助脚本
├── tools/normalize-eol.ps1      换行归一（CRLF→LF、剥离 UTF-8 BOM）
├── gputemp.plg.tmpl             .plg 模板（SHA256 占位，构建时回填）
├── build.ps1                    一键构建流水线（门禁 → 归一 → bsdtar 打包 → 哈希回填）
├── validate.ps1                 静态门禁（php -l / 零 CR / 零 BOM / 黑名单 grep 等）
├── verify-plg.ps1               .plg 产物一致性复核脚本
├── diff-zip.ps1                 发布 zip 与工作区一致性比对脚本
├── .gitattributes               文本文件强制 LF 行尾、二进制产物不做归一
├── .gitignore                   忽略临时/系统垃圾（dist/ 有意保留，详见文件内注释）
├── LICENSE                      MIT 许可证（见第 8 节）
├── CONTRIBUTING.md              贡献指南（开发流程、版本递增纪律）
└── README.md                    本文件
```

---

## 3. 安装

### 安装前提

- **NVIDIA**：已安装 NVIDIA 驱动且 `nvidia-smi` 可用；
- **AMD / Intel**：对应内核驱动（`amdgpu` / `i915` / `xe`）已加载，
  `/sys/class/hwmon/hwmon*/temp1_input` 节点存在；
- Unraid 版本 ≥ 7.2.0-beta。

`.plg` 的 pre-install 脚本会复核版本与数据源判据（`command -v nvidia-smi` 或任一
hwmon `temp1_input` 存在），任一不满足即中止安装并输出原因（退出码 ≠ 0）。

### 安装步骤（离线安装）

本插件**无公网托管**，`.plg` 中的包地址采用离线语义（已对照 Unraid 官方
plugin 管理器脚本 `dynamix.plugin.manager/scripts/plugin` 查证）：

- `<FILE Name="…" SHA256="…">` 的目标文件若**已存在且 SHA256 匹配**，管理器
  完全跳过抓取，直接执行 `upgradepkg --install-new`（日志
  `skipping: <file> already exists`）——这是离线安装的主路径，**全程零联网**；
- `<URL>` 为 `file://` 协议（官方下载器 wget 原生支持），指回同一 U 盘路径，
  仅作预置缺失/损坏时的明确失败回退，不会访问外网；
- `pluginURL` 指向本机已安装的 `.plg` 副本，"检查更新"只与本地状态比对，
  不访问外部主机；待有公网托管后将两实体改回 `https://` 即可。

1. 将构建产物 `dist/gputemp.plg` 拷贝至 U 盘：

   ```text
   /boot/config/plugins/gputemp.plg
   ```

2. 将安装包 `dist/gputemp-2026.08.10-x86_64-1.txz` **一并**拷贝至
   （必须与 `.plg` 内 `Name` 路径逐字节一致，SHA256 匹配即跳过下载段）：

   ```text
   /boot/config/plugins/gputemp/gputemp-2026.08.10-x86_64-1.txz
   ```

3. 任选其一完成安装：

   - **WebGUI**：Plugins 页刷新后点击 `gputemp` 安装；
   - **命令行**：

     ```bash
     installplugin /boot/config/plugins/gputemp.plg
     ```

> **兜底替代路径**（不经过插件管理器，仅装包、不由管理器登记）：
> `upgradepkg --install-new /boot/config/plugins/gputemp/gputemp-2026.08.10-x86_64-1.txz`
> ——该方式不会建立 `/var/log/plugins/gputemp` 符号链接，Plugins 页无法
> 管理/卸载本插件，且重启后不会自动重装，**仅在上述主路径异常时应急**，
> 正常情况下请使用 `installplugin`。

安装成功后：

- `/boot/config/plugins/gputemp/`（持久配置目录，位于 U 盘，重启保留）；
- `/usr/local/emhttp/plugins/gputemp/`（运行目录，位于 tmpfs，开机由 `.plg` 重建）；
- Dashboard 出现"GPU温度"磁贴；Settings 菜单出现本插件设置页。

---

## 4. 升级

1. 用新版 `gputemp.plg`（及配套 txz）覆盖同路径文件（两个文件缺一不可，
   新版 txz 的 SHA256 写入 `.plg`，旧包哈希不符会被管理器删除并回退到
   `file://` 抓取同一本地路径）：

   ```text
   /boot/config/plugins/gputemp.plg
   /boot/config/plugins/gputemp/gputemp-<新版本>-x86_64-1.txz
   ```

2. 重新安装：

   ```bash
   installplugin /boot/config/plugins/gputemp.plg forced
   ```

   > 官方 `plugin install` 对"同版本已安装"会直接拒绝（`not reinstalling
   > same version`）；版本号未变但包内容变更时需附加 `forced` 参数。
   > 离线环境下 `plugin check/update` 依赖的 `pluginURL` 指向本机副本，
   > 不会报"有更新"，升级请走上述覆盖 + `installplugin` 流程。

**配置保留**：用户配置存放于 `/boot/config/plugins/gputemp/gputemp.cfg`，
位于 U 盘持久分区，升级不清空；旧键 100% 保留，新版本新增键自动回退
`default.cfg` 默认值（AC-CFG-05）。post-install 脚本会自动清理旧版本 txz 残留。

---

## 5. 标准卸载（保留配置）

在 Plugins 页点击本插件的 **Remove**，或命令行执行：

```bash
removepkg gputemp-2026.08.10-x86_64-1
```

标准卸载**只**执行 `removepkg` + 删除 tmpfs 运行目录
（`rm -rf /usr/local/emhttp/plugins/gputemp`）。`.plg` 的 remove 段针对
`/boot` 的删除语句数 = 0，这是本插件的显式设计需求（FR-6.2 / AC-INST-04），
并非 Unraid 平台默认行为。

卸载后保留：

| 位置 | 内容 |
|---|---|
| `/boot/config/plugins/gputemp/gputemp.cfg` | 用户配置（逐字节保留） |
| `/boot/config/plugins/gputemp/` | 配置目录（含历史 txz 清理后的空目录） |

重新安装同版本后，用户配置 100% 恢复为卸载前取值。

---

## 6. 彻底清除

在标准卸载（第 5 节）完成之后，显式删除 U 盘上的配置目录（FR-6.2 / AC-INST-05）：

```bash
rm -rf /boot/config/plugins/gputemp/
```

亦可使用设置页的"清除配置"按钮（独立 POST + CSRF 校验，带不可撤销确认）。
彻底清除后以插件名全盘检索（排除 `/var/log/` 历史日志）命中文件数应为 0，
Plugins 列表无残留条目。

---

## 7. 配置说明

用户配置文件：`/boot/config/plugins/gputemp/gputemp.cfg`（`KEY="value"`、LF）。
经官方 `parse_plugin_cfg('gputemp')` 与随包 `default.cfg` 合并（用户值优先，
缺键回退默认值）。共 6 个配置键：

| 键名 | 默认值 | 合法范围 | 含义 |
|---|---|---|---|
| `REFRESH_INTERVAL` | `5` | ∈ {`1`, `2`, `5`, `10`}（秒） | Dashboard 温度刷新周期。**1s 档仅建议单张 NVIDIA 独显环境使用**：若单次采集耗时超过刷新周期，该周期自动跳过（不堆积） |
| `TEMP_WARN` | `65` | 整数，0 < TEMP_WARN < TEMP_CRIT ≤ 120（℃） | 警告级超温阈值，温度高于该值显示警告色 |
| `TEMP_CRIT` | `85` | 整数，TEMP_WARN < TEMP_CRIT ≤ 120（℃） | 临界级超温阈值，温度高于该值显示临界色 |
| `ENABLED_GPUS` | `""`（空串） | 逗号分隔 PCI 地址，格式 `0000:xx:xx.x`（十六进制域 + 功能号 0-7）；空串合法 | 启用显示的显卡白名单。空串 = 显示全部检测到的受支持设备；白名单中的不可用项不自动剔除 |
| `COLLECT_TIMEOUT` | `5` | 整数 ∈ [1, 30]（秒） | 单次采集超时阈值，超时即判为本次采集失败（超时进程经 SIGTERM→SIGKILL→回收，零僵尸） |
| `FAIL_THRESHOLD` | `3` | 整数 ≥ 1（次） | 连续失败降级阈值：某卡连续失败达该次数后停止采集并标注 `[不可用]`，一次成功即清零 |

保存校验由 `save.php` 执行（7 项校验：4 类取值范围 + 全部值不含 `<>` +
不含换行 + CSRF 显式校验前置于任何写入）。非法值经 `gputemp_cfg()`
回落默认并记 syslog，不向浏览器输出错误细节。

---

## 8. 许可证

本项目采用 **MIT 许可证** 发布（Copyright © 2026 gputemp contributors），
与 Unraid 社区插件惯例一致。完整条款见仓库根目录 [`LICENSE`](LICENSE) 文件。
