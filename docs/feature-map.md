# WP Title Layer 1.0.0-rc.2 功能地图

这份地图把最初设想中的标题语义层，映射到当前插件的实际能力与责任
边界。它也是下一轮正式站点验收的清单。

0.3.2 之后的开发已拆分为五个可独立验收的阶段，详见
[分阶段路线图](roadmap.md)。0.8.0 已完成第五阶段：Series 媒体封面、
Classic Editor 安全入口，以及经官方模板验证的 Kadence Archive 卡片副标题；
0.8.1 在此基础上补齐简体中文与标准 WordPress 翻译链路；0.8.2 根据正式站点
验收修正页码导航语义、Kadence 卡片副标题字号，完善作者与菜单识别，并将共享
设置页按放置、呈现、阅读和搜索／分享职责整理为响应式分区。0.9.0 进一步
明确 Category 与 Series 的协作关系，允许每个 Series 覆盖 Archive 呈现策略，
并将同一原生保存表单渐进增强为可访问的标签页。0.9.1 收口可见层细节：
结构化归档可选择摘要、分页与主题视觉协调，Series 可选择装饰图标，编辑器
改用插件自有图标，并澄清空 role 与显式主要文章 role 的区别。0.10.0 新增
逐 Series 明确启用的 Sequence Manager：既有顺序先预览、再分批初始化，启用后
可以插入、拖动、键盘移动和撤销，而不再让作者重排后续整数；同时为 Season
分配改名和重排后仍稳定的数字公开 ID。1.0.0-rc.2 在此基础上把书籍式内容明确
拆为 Series／Season 范围与序文／主要文章／尾声／附录角色。统一的“篇序与结构”
页面将有序正文篇序与可选结构轨道分开管理。

## 1. 内容语义层

| 关注点 | 当前所有者 | 1.0.0-rc.2 状态 |
| --- | --- | --- |
| Primary Title | WordPress `post_title` | 完成；插件不改写原生主标题 |
| Subtitle | `wptl_subtitle` | 完成；兼容读取与迁移 Secondary Title / 可信 ACF subtitle |
| Series / Kicker | `wptl_series` taxonomy + 显示层 | 完成；每篇文章至多一个 Series |
| Series Lifecycle | `wptl_series_status` term meta | 完成；显式状态且不推断旧数据 |
| Series Health | 只读后台报告 | 完成；兼容检查旧位置，并检查托管 rank 损坏与旧字段漂移 |
| Category branch | `wptl_parent_category_id` term meta | 完成；可选归属分支，不改写文章 Category |
| Navigation Scope | `wptl_navigation_scope` term meta | 完成；旧数据默认全系列，可显式限制当前季 |
| Season / Order | Sequence service + Series/article meta | 完成；旧 Series 原样兼容，托管 Series 支持插入式排序和自动篇号 |
| Season public identity | `wptl_seasons.public_id` | 完成；数字 URL 稳定，旧 key URL 兼容并规范跳转 |
| Book scope / role | `wptl_series_scope` + `wptl_series_role` | 完成；范围与角色正交，既有不明确数据先预览、不猜测 |
| Structure tracks | “篇序与结构”管理器 + 私有 rank | 完成；篇序独立，Series／各季的序文、正文、尾声、附录分轨编排 |
| Display Template | WP Title Layer | 完成；文章 > Series > Category > 全局默认 |
| Series icon | `wptl_icon_id` + 显示策略 | 完成；默认关闭，只作为题头链接装饰，不进入标题语义 |
| Landing / Canonical Audit | 只读后台报告 | 完成；精确对比 Series 与 Category 公开文章集合 |
| SEO Title | Rank Math 等 SEO 插件 | 可选集成；WP Title Layer 只提供变量，不接管 metadata |
| Social Title | Rank Math 等 Open Graph 提供者 | 可选集成；与 SEO Title 独立选择 |
| Interface language | WordPress locale + WP Title Layer language pack | 完成；英文源语言、内置简体中文，其他语言保留标准翻译入口 |

## 2. Single 文章页

已经完成：

- 结构保持为 `Series / Season / Order → H1 → Subtitle`，没有把这些字段
  拼回 `post_title`；
- 区块主题与已验证的 Kadence 标题槽可以原位接管；不支持的经典主题
  使用手工区块、短代码或主题 API；
- Standard、Editorial feature、Inline、Title only 四种模板分别对应常规
  文章、长文／专题、带 Series 题头的紧凑同行标题、以及严格主题兼容；
- 四种模板可安全调整 Series 显隐、Subtitle 分行／同行／隐藏和分隔符；
- Series 名称在可公开访问时链接到 Series Archive；Season 名称独立链接到
  当前季的筛选列表；篇序仍为链接外的普通文字。链接默认继承主题文字颜色，
  只在 hover / focus 时使用主题强调色并显示下划线；
- Series 可选择一个独立于封面的媒体库图标；站点默认关闭，每个 Series 可
  继承、显示或隐藏。图标仅出现在真实 Series 题头链接内，使用装饰性空 `alt`，
  位于 H1 之外，也不进入 `post_title`、SEO 或社交标题；
- Subtitle 编辑框保留多行数据能力，但边栏高度缩为两行。
- 有序且有季的 Series 可在自身编辑页选择导航跨越全系列，或只在当前季内
  计算上一篇、下一篇、起点和进度；旧 Series 默认保持跨季。
- 已由 Sequence Manager 管理的有序 Series 使用自动连续篇号；人工 Public label
  仍可覆盖可见文字，内部 rank 不进入 H1、编辑字段或公开 API。
- 1.0.0-rc.2 中序文、尾声与附录各自使用私有结构轨道，不占用主要文章的自动
  篇号；可用 Public structure label 显示 `0-1`、`0-2`、`附录 A` 等公开记号。

## 3. Archive

### Theme archive layout（默认、推荐）

主题继续渲染 Series Archive，因此在 Kadence 等主题中通常与 Category
Archive 保持一致。有序 Series 始终按规范阅读顺序；无序 Series 才使用后台
选择的日期正序、日期倒序或标题排序，并在主题较晚改写查询时仍保持该选择。
Archive 卡片是否显示 Subtitle 通常由主题模板决定；在官方 Kadence 1.5.x 的
已验证模板中，可单独开启“Theme archive card subtitles”，把 Subtitle 放在
卡片标题之后、元信息之前。该开关默认关闭，且不会进入 Category、Search、
Home、Structured 或其他主题的循环。插件不会向所有主题盲目注入字段。

### Structured Series layout（可选）

插件提供带 Series 简介、封面、Season 分组、篇序和分页的结构化列表。桌面
容器不再复用主题的 `#primary`；无封面时标题区使用可用内容宽度，有封面时
才切换为受限的两栏头部，标题字号也保持文章归档层级。文章 Subtitle、Excerpt
与特色图像分别拥有独立开关。0.9.1 可按 Series 单独选择继承、主题或结构化
布局，并分别覆盖 Subtitle、Excerpt 与紧凑特色图像；Excerpt 和特色图像的
全站默认均关闭，旧 Series 全部继承全站设置，升级不会改变现有输出。
单季链接使用同一 Archive 的 `wptl_season` 数字筛选，并可返回全系列；旧的
Season key 参数仍可访问并规范跳转到稳定数字 URL。若 Series
指定了归属 Category，结构化页头会提供返回该 Category 的链接。

启用高级结构后，后台管理器仍显示精确的范围／角色轨道；前台不直接暴露这些
程序化名称。同一季的序文、主要文章、尾声与附录合并在一个季标题下，并保持
规范轨道顺序。全系列范围的非主要条目分别使用“系列＋Public structure label”
作为标题，例如“系列总序”“系列后记”；未填写公开标签时仅显示“系列”。

结构化分页使用方形页码按钮、实心当前页、前后箭头和无按钮外观的省略号；
链接在默认、hover 与 focus 状态都不出现下划线，并保留清楚的键盘焦点与
读屏分页文案。

Category、Tag 和其他 Archive 永远不会被这个结构化模板替换。若旧 Category
和新 Series 收录相同文章，正式上线前仍需确定哪一个才是该系列的 canonical
landing page，避免两个近似入口长期并存。

**WP Title Layer → Landing & Channels** 逐个 Series 对比公开文章集合。只有
Series 与 Category 的完整公开集合完全一致，才标记为 exact duplicate；部分
交集只显示共享／Series／Category 三个数量。草稿、私密、定时、回收站与密码
保护文章不会制造公开入口重复结论。报告本身不删除 Category，也不写 canonical。

## 4. 后台编辑流程

- 新建 Series 时先提供四个 Season 行，并可在保存前继续点击增加；
- Series 封面与题头图标都通过 WordPress 媒体库选择，分别保存为独立附件 ID；
  媒体脚本不可用时保留数字 ID 输入，不改写旧值；
- 文章编辑器只保留 WP Title Layer 的单 Series 选择器，不重复显示原生标签式
  面板；Title Layer 面板、可选动态区块与后台菜单共用插件自有标题层图标；
- Series 可选归属一个 Category 分支；文章位于该 Category 或任一下级 Category
  即视为一致。编辑器与 Series Health 提示不一致，但不阻止保存，也不自动改
  文章 Category；
- 若站点实际关闭 Gutenberg，Classic Editor 自动得到一套受 nonce 与文章权限
  保护的 canonical 字段，不与区块编辑器重复，也不在文章页创建新 Series；
- 文章列表提供可由“显示选项”开关的 Subtitle 栏，便于核对迁移与编辑结果；
- 有序 Series 可在“篇序与结构”的篇序视图中逐个初始化。预览会列出可确定顺序和
  待编排项；初始化可分批继续或按值回退，提交标记最后写入，因而中断不会让
  前台读取半套 rank；
- 托管后可在有界分页列表中拖动或用键盘移动，也可精确搜索跨页锚点后插入。
  每次保存校验 revision，提供一次受值校验保护的撤销；新文章及改季文章安全
  追加到目标范围末尾；
- 设置页保留单一 WordPress 原生保存流程；无 JavaScript 时显示全部响应式分区，
  JavaScript 可用时升级为支持键盘和 URL hash 的标签页；
- WP Title Layer 菜单提供 Series Health：逐个 Series 汇总缺季、缺篇序、非法值、
  重复位置或 rank、旧字段漂移与文章可见状态；每页只取 50 篇并提供原生文章
  编辑链接；Series 总览另可按名称或别名搜索并保留筛选分页。Health 保持只读，
  实际排序与修复入口统一交给“篇序与结构”；管理器的 Series 搜索选择器选中即打开，
  并保留无脚本时的原生下拉与按钮。

1.0.0-rc.2 为文章增加独立的书籍式范围：平铺 Series 的所有角色均属全系列；
分季 Series 的主要文章必须属于一季，非主要角色可明确选择“全系列”或“单季”。
旧的 `intro`、`epilogue`、`appendix` 若无法从已有 Season 确定范围，只进入逐
Series 只读预览，不会自动写成全系列。新建的空 Series 直接获得规范结构；已有
Series 仍须由管理员逐个确认。

## 5. SEO 与转发标题

显示标题、搜索结果标题和社交转发标题是三个独立渠道。0.9.1 在 Rank Math
中注册：

- `%wptl_subtitle%`
- `%wptl_series%`
- `%wptl_title_with_subtitle%`

是否把 Subtitle 加进 SEO、Facebook 或 Twitter 标题，由站点在 Rank Math
相应字段中分别决定。插件不自动写入 Rank Math post meta，不覆盖已有逐篇
设置，也不自行输出 Open Graph 标签。

内置组合标题分隔符随当前语言显示：英文使用半角冒号及空格，简体中文使用
全角冒号；自定义分隔符不随语言改变。Series Archive 上的组合标题变量只返回 Series 名称，不会误取循环中第一篇
文章的标题或 Subtitle。

Landing & Channels 在 Rank Math 激活时进一步读取当前 Series taxonomy 的
title、description、robots、sitemap 开关，以及逐 Series 的 title、canonical、
robots、Facebook 与 Twitter 覆盖。它也检查文章类型默认 SEO title 和 Series
成员中已有的逐篇覆盖是否使用 WP Title Layer 变量。Rank Math 未激活时，残留
option 不会冒充当前配置；检测到其他提供者或多个提供者时，只标明渠道所有权
需要在原插件与页面源码中确认。

## 6. 迁移与 ACF

已经完成：只读扫描、冻结候选、分批复制、冲突报告、精确回滚与旧插件
migration-only 模式。Secondary Title 源数据默认保留。

五个旧 ACF Series-like 字段只做审计，不自动建立 Series。报告中的非空值
可能包含 ACF 保存的 `0`、`false` 或默认值，不能直接当成真实 Series 使用。
WP Title Layer 本身不依赖 ACF；卸载 ACF 前仍需另行确认主题或其他插件没有
使用其余 ACF 字段。

## 7. 隔离测试站验收顺序

1. 只在隔离的非生产 WordPress 测试站创建带 `WPTL 0.11 QA` 前缀的模拟 Series、
   Season 与文章，不触碰正式站或测试站中无关的原有内容；
2. 覆盖平铺／多季、有序／无序四种组合，并在多季组合中同时建立全系列序文、
   单季序文、主要文章、单季尾声、全系列尾声和多篇附录；
3. 验证既有 Series 只出现只读兼容预览；范围不明确时拒绝启用，明确修正后才
   建立结构轨道；验证新建空 Series 可直接进入规范模型；
4. 在“篇序与结构”中以鼠标、键盘和精确前后插入移动篇序或各轨道文章，确认
   序文／尾声不占主要文章篇号，撤销与 revision 冲突保护有效；
5. 核对完整 Archive、单季筛选、Single 题头、上一篇／下一篇、起点、进度与
   Series Health 使用同一范围／角色解释；单季页不得混入全系列序文或尾声；
6. 验证媒体附件编辑页不出现 Title Layer，文章快速／批量编辑不出现会误导为
   多选标签的原生 Series 控件；必要时清理且只清理本轮创建的 QA 数据。

## 8. 多语言与本地化

- PHP 后台、Classic Editor、报告、前端标签和 Gutenberg 侧栏均使用
  `wp-title-layer` text domain；
- 后台跟随当前用户的 WordPress 语言，前台跟随当前请求语言；
- 发布包内置完整简体中文（`zh_CN`）翻译，英文是源语言与降级语言；
- 内置冒号、破折号和竖线按语言选择英式或中式字形，但数据库仍保存同一
  语义选项；自定义分隔符保持原样；
- `languages/wp-title-layer.pot` 为其他语言保留 WordPress 标准翻译入口；
- WordPress、Kadence 或其他插件自己提供的字段文案仍由各自语言包负责。

## 9. 暂不扩大的边界

- 托管 rank 是私有实现；不开放作者直接输入，不删除或反向覆盖旧 position；
- 结构轨道的私有 rank 不作为文章字段、REST 输入或公开篇号；
- 一篇文章同时属于多个 Series；
- 对所有经典主题自动识别标题槽；
- 向任意未知主题的 Category / Archive 卡片自动注入 Subtitle；
- 绕过 SEO 插件直接接管 document title 或 Open Graph metadata。

这些能力需要新的关系模型或逐主题／逐 SEO 提供者适配，不应混入现有稳定
路径。
