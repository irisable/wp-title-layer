# WP Title Layer 1.0.0-rc.1 验收记录

记录日期：2026-08-29  
候选版本：`1.0.0-rc.1`  
发布提交、发布包与 SHA-256：由对应 GitHub Pre-release 记录

本记录区分自动验证、既有人工验收和本候选版尚待执行的交互验收。没有执行的
项目明确标为“未测”，不会因为源码测试通过而自动视为人工通过。

## 1. 源码候选自动验证

| 项目 | 环境 | 结果 |
| --- | --- | --- |
| JavaScript、编辑器、本地化、迁移、Series、显示与发布版本契约 | Node.js，本地工作树 | **通过：95/95** |
| PHP 语法与 WordPress 集成 | WordPress 6.5 / PHP 7.4 | **通过：60 文件；827 项集成；111 项 Sequence；61 项书籍结构** |
| PHP 语法与 WordPress 集成 | WordPress 7.1 / PHP 8.3 | **通过：60 文件；827 项集成；111 项 Sequence；61 项书籍结构** |
| 官方 Kadence 主题适配 | Kadence 1.5.2 / WordPress 7.1 / PHP 8.3 | **通过：40/40** |
| Rank Math 集成 | Rank Math 1.0.276 / WordPress 7.1 / PHP 8.3 | **通过：32/32** |
| 简体中文 PO/MO | `msgfmt --check`，未翻译条目检查 | **通过：0 个未翻译条目** |
| JSON、Bash 与补丁空白 | JSON 解析、`bash -n`、`git diff --check` | **通过** |

外部兼容测试使用的官方包：

- Kadence 1.5.2 ZIP SHA-256：`4773b41cd2da71bdd2519aedc8bb671ec0d4bec33acf6db6592a40c614c2461b`
- Rank Math 1.0.276 ZIP SHA-256：`5252b6e233fe6fe73e69b57fbb039b50140bb5f12438da0ada1c2a973a99f83d`

## 2. 发布包门禁

- [ ] 从固定发布提交构建 `wp-title-layer-1.0.0-rc.1.zip`。
- [ ] 插件头、`WPTL_VERSION`、`package.json`、Stable tag 和 ZIP 文件名完全一致。
- [ ] 同一提交连续构建两次，ZIP 字节与 SHA-256 一致。
- [ ] 解压后的安装包不包含 `.github`、`bin`、`docs`、测试、开发依赖、私人材料或第三方 ZIP。
- [ ] 对解压安装包重复 WordPress 6.5/PHP 7.4 与 WordPress 7.1/PHP 8.3 全矩阵。
- [ ] 在空白站完成安装、启用、停用、重新启用与卸载前的数据保留检查。

当前状态：**待冻结提交后执行**。

## 3. 既有人工验收基线

以下行为已在 0.11.0 开发期间经过正式使用或隔离测试站验收，并由
`1.0.0-rc.1` 自动回归继续覆盖其数据契约：

- Secondary Title 与 ACF 只读扫描、保守导入及来源数据保留；
- 单篇文章、Archive、Series 题头、Season 链接和篇尾导航；
- 平铺／分季、有序／无序 Series；
- 独立 Sequence 管理与可选书籍式结构轨道；
- 搜索式 Series 选择、选择即打开、无 JavaScript 原生回退；
- Series Health 搜索与分页；
- 中英文后台及前端显示。

这部分是升级基线，不替代候选 ZIP 的独立安装验收。

## 4. 1.0.0-rc.1 人工验收

在隔离的非生产 WordPress 站点，用最终 GitHub Release ZIP 执行：

- [ ] **未测**：从当前 0.11.0 覆盖升级，设置、Subtitle、Series、Season、篇序与结构均保持。
- [ ] **未测**：全新安装后普通文章编辑、保存、刷新及前台仅有一个标题层。
- [ ] **未测**：旧 Secondary Title 同时启用时保持 migration-only，不出现双重前台输出。
- [ ] **未测**：有序短 Series 不启用书籍结构也可进入 Sequence 管理并插入文章。
- [ ] **未测**：多季 Series 的季链接、全系列/单季导航及多篇序文、尾文、附录。
- [ ] **未测**：桌面和手机端的标题、副标题、Series Archive、分页与上一篇/下一篇。
- [ ] **未测**：简体中文用户界面与英文用户界面分别使用对应标点和文案。
- [ ] **未测**：无 JavaScript和纯键盘进入 Sequence & Structure、选择 Series 及移动条目。
- [ ] **未测**：最低权限边界；无管理权限用户不能进入设置、迁移或结构管理。
- [ ] **未测**：WP Publisher 通过 REST 创建、更新、清空 Subtitle/Series/Season/role/scope/label，并正确处理旧未托管有序 Series。

## 5. 升为 1.0.0 的条件

- 最终 RC ZIP 的自动门禁全部通过；
- 上述核心人工验收没有数据损坏、重复标题或顺序契约问题；
- WP Publisher 外部集成没有要求改变已冻结的公共字段语义；
- RC 实际使用期只出现可兼容修复；若需改变公共契约，先发布新的 RC。
