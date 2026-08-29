# WP Title Layer 0.10.0 人工验收清单

本清单重点验证 0.10.0 的 Series Sequence Manager、自动篇号与稳定 Season
数字 URL，同时覆盖最容易受排序改造影响的既有前台路径。建议先备份数据库，
并优先用可撤销的测试 Series；不要为了覆盖测试而故意破坏正式长系列。

## 0. 样本准备与基线

建议准备四组样本：

- A：较短的 flat + ordered 旧 Series，至少 5 篇；
- B：seasoned + ordered Series，至少两季；
- C：unordered Series；
- D：一篇不属于任何 Series 的普通文章。

升级前或首次打开 Manager 前，记录 A、B 的 Single 题头、Theme／Structured
Archive 顺序、上一篇／下一篇，以及 B 的现有 Season 筛选 URL。

- [ ] 插件列表显示版本 `0.10.0`，覆盖安装后无 PHP 错误或后台警告。
- [ ] 未打开 Sequence Manager、未初始化任何 Series 时，A、B 的前台顺序与
  0.9.x 基线一致。
- [ ] 升级没有改写文章标题、副标题、Series、Season、旧篇序或 Public label。
- [ ] C 仍按无序 Series 的 Archive 排序选项工作；D 的标题层与正文无变化。

## 1. 初始化预览：先读后写

进入 **WP Title Layer → Sequence Manager**。

- [ ] 选择器只把有序 Series 作为可初始化／编排对象；无序 Series 不被伪造为
  阅读序列。
- [ ] 选择 A 后先显示旧顺序预览，文章标题、状态、旧位置和待编排提示符合实际。
- [ ] 只浏览、翻页或离开预览不会启用 Manager，也不会改变前台。
- [ ] 若预览含重号、缺号或非法位置，系统不会暗自猜顺序；只有明确解决或选择
  按日期追加后才允许形成完整初始化方案。
- [ ] 确认初始化 A 后，批次自动继续直至完成，最终显示已由 Sequence Manager
  管理；刷新页面后状态保持。
- [ ] 初始化完成后旧 `wptl_sequence_position` 与 Public label 仍保留，没有被
  批量重写或删除。

不必人工模拟“批次中断”；自动测试已覆盖继续、部分回退、完整回退、源值漂移
和提交标记最后写入。若真实操作意外中断，则记录页面提示，不要手动编辑数据库。

## 2. 核心插入与自动篇号

在已初始化的 A 中，选择原来相邻的两篇，例如第 21 与 22 篇，并插入一篇到
两者之间。

- [ ] 只移动待插入文章，不需要把原 22 及后续文章逐篇加一。
- [ ] 保存后 Manager 顺序立即正确，刷新后不反弹。
- [ ] 普通文章的可见篇号自动连续；设置了 Public label 的文章仍显示该标签。
- [ ] Single 题头、Theme Archive、Structured Archive、文章末尾上一篇／下一篇、
  从头阅读与进度均使用同一顺序。
- [ ] 页面或 HTML 中不显示内部 rank 数值；作者界面也不要求填写 rank。

## 3. 三种移动方式与大 Series 页面

- [ ] 鼠标拖动一篇后保存，位置正确，拖动柄与链接不会误触。
- [ ] 仅用键盘完成上移、下移、移到开头和移到末尾；焦点和状态提示可感知。
- [ ] 用精确标题搜索另一页的文章，并把当前文章放到它之前或之后；跨页插入正确。
- [ ] Manager 一页只加载有限数量文章；翻页后仍可正常移动与搜索。
- [ ] 手机宽度下列表改为可读的纵向信息，不出现必须横向滚动才能操作的控件。

## 4. 撤销、并发与安全失败

- [ ] 完成一次手工移动后执行“撤销上一次编排”，原顺序恢复；刷新后仍正确。
- [ ] 撤销只影响最近一次 Manager 移动，不改文章内容、Series、Season、旧位置
  或 Public label。
- [ ] 可选：同一 Series 在两个后台标签页打开；标签页一先保存后，标签页二的
  旧页面保存被拒绝并要求刷新，不覆盖新顺序。
- [ ] 搜索锚点已被移走、文章已换 Series／Season 等情况下，旧请求安全失败，
  不产生重复、丢篇或半套顺序。

## 5. 多季 Series 与稳定数字 URL

在 B 上完成初始化，并分别检查每一季。

- [ ] Manager 为每一季提供独立标签／范围；在第一季移动文章不会改变第二季顺序。
- [ ] 各季普通文章自动篇号从 1 开始计算。
- [ ] Single 题头的季名链接使用 `?wptl_season=1`、`?wptl_season=2` 等数字。
- [ ] 旧 `?wptl_season=s1`、`season-2` 或中文 key 地址仍可访问，并规范跳转到
  对应数字 URL；分页参数在跳转后仍保留。
- [ ] 在专用测试 Series 中给 Season 改名、改变排列顺序后，其数字 ID 不变。
- [ ] 删除一个 Season 后新增 Season，新 Season 获得更大的新 ID，不复用已删除 ID。
- [ ] 尝试从页面提交篡改 Public URL ID 时，服务端仍保留已有稳定 ID。
- [ ] “全系列”导航可以按设定跨季；“当前季”导航在季首／季尾正确停止。

## 6. 新文章与关系变化

- [ ] 在已托管的 A 新建一篇普通文章并加入该 Series，保存后自动追加到末尾；
  编辑器提示可到 Manager 移动。
- [ ] 将一篇文章从一个托管 Series／Season 移到另一个，旧范围不再包含它，
  新范围末尾出现它，两边篇号与导航都刷新。
- [ ] 将托管文章移入回收站后不再占自动篇号；恢复后安全追加并可再次移动。
- [ ] 改变序文／正文／尾文等既有 role 后，普通正文自动篇号缓存不会保留旧结果。
- [ ] Gutenberg 右侧 Title Layer 面板显示自动篇号和 Manager 链接，不再显示旧
  手工位置输入；链接会跟随当前所选 Series／Season。
- [ ] 若站点使用 Classic Editor，其 meta box 行为相同；若没有 Classic Editor，
  此项记为“不适用”。

## 7. Series Health 与损坏修复入口

- [ ] 未初始化的 Series 仍按旧位置报告缺失、非法与重复问题。
- [ ] 已托管 Series 按 rank 检查，但列表只显示自动篇号，不泄露内部 rank。
- [ ] 托管后若旧位置被外部插件或导入程序改变，Health 标为“旧字段已变化但不
  影响当前顺序”，前台顺序保持不变。
- [ ] 缺失、非法或重复 rank 的损坏记录仍能出现在有界 Manager 页面末尾并可修复，
  不会因为数据库查询而被隐藏。
- [ ] Health 本身保持只读；修复按钮进入对应 Series／Season 的 Manager。

不建议在正式站点手工删除 rank 制造损坏；这一项可直接采用自动测试结果，除非
已有自然损坏样本。

## 8. 前台与既有功能回归

- [ ] 桌面和手机 Single：Series、Season、自动篇号、H1、Subtitle 的层级与字号正常。
- [ ] Series／Season／文章导航链接默认无下划线，hover／键盘 focus 时按主题样式变化。
- [ ] Theme Archive 仍继承主题布局；Structured Archive 的摘要、特色图、Subtitle、
  分页与每 Series 覆盖设置均保持原行为。
- [ ] Category、Tag、Search、Home 不被 Structured Series 设置误改。
- [ ] Series icon 仍在 H1 外，SEO 与社交标题变量保持纯文本。
- [ ] Parent Category 不一致只警告，不自动改变文章 Category。
- [ ] Secondary Title／ACF 的旧迁移扫描与导入入口仍可用；Sequence 初始化不会
  清理这些来源数据。
- [ ] Rank Math 的 `%wptl_subtitle%`、`%wptl_series%`、
  `%wptl_title_with_subtitle%` 继续正常，Sequence rank 不进入 SEO／分享标题。

## 9. 验收记录

- 测试站点／主题：
- WordPress / PHP：
- 从哪个插件版本升级：
- 已初始化的测试 Series：
- 未测试或不适用项：
- 发现问题、页面 URL 与复现步骤：
- 结论：通过 / 有条件通过 / 不通过
