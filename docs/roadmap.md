# WP Title Layer 分阶段路线图

这份路线图从 0.3.2 已经可用的标题、Series、迁移、Archive 与 Rank Math
能力继续推进。每一阶段都形成独立闭环；后一阶段不会以破坏前一阶段的数据
模型为代价。

0.9.0 之后的划分已根据正式站点的实际编排经验重做。其中有序
Series 不再继续扩充“手工填数字位置”的工作流，而是在 0.10.0 引入
正式的 [Series Sequence Manager](sequence-manager-design.md)，为 0.11.0 的书籍式结构
提供唯一排序基础。

## 第一阶段：Series 生命周期语义

状态：0.4.0 已完成。

- 为 Series 增加“筹备中、连载中、暂停、已完结”四种显式状态；
- 旧 Series 和未选择状态的新 Series 均保持“未设置”，不根据发布日期或篇数
  自动猜测；
- 在 Series 新建／编辑页和后台列表中显示状态；
- 通过受权限保护的 WordPress REST term meta 提供状态；
- 结构化 Series Archive 在状态已明确时显示一个轻量标签；
- 状态仅作描述，不自动发布、隐藏、锁定或重排文章。

验收标准：旧数据零改写；四状态可保存、清除和重新读取；未设置不产生前台
标签；改变状态不改变 Archive 文章集合、顺序或 Reader 导航。

## 第二阶段：Series 内容健康检查

状态：0.5.0 已完成。

- 提供一个只读的 Series 检查页；
- 检查有序 Series 的缺失篇序、重复篇序和非法 Season；
- 区分草稿、定时、私密、已发布与密码保护文章；
- 给出可点击的修复入口，但不自动修改内容；
- 对大型 Series 分页／分批检查，避免一次载入全部文章。

实现说明：整个 Series 的统计由数据库聚合，文章明细每页最多 50 条；报告区分
已发布、定时、私密、草稿、待审、回收站与密码保护状态。草稿／待审缺口作为
制作中提示，已发布／定时／私密缺口单列为当前应处理事项。检查页没有保存表单，
也不写入文章、term meta 或 Series 关系。

验收标准：报告与真实文章状态一致；检查本身不写数据库；异常可定位到文章，
且无序 Series 不会被误报为缺少阅读顺序。

## 第三阶段：阅读导航范围

状态：0.6.0 已完成。

- 为有季 Series 增加“全系列”与“当前季”两种导航／进度范围；
- 上一篇、下一篇、当前位置和总数共用同一公开文章集合；
- 跨季行为由 Series 设置明确决定，而不是由模板暗中推断；
- 保持现有整系列导航为升级后的兼容默认值。

实现说明：范围是每个 Series 的 term meta，而不是一个站点级开关。仅有序且
有季的 Series 会应用“当前季”；旧 Series、缺失值、非法值和 flat Series 都
继续使用全系列范围。显式选择“当前季”后，上一篇、下一篇、从本季开头、
当前位置和总数从同一公开集合产生；文章缺季或指向未定义季时安全地不输出
导航，并交给 Series Health 定位，而不是猜测或越过边界。

验收标准：季首、季末、系列首尾与跨页位置均稳定；草稿、私密、定时和密码
文章不进入公开导航或计数。

## 第四阶段：Landing Page 与渠道治理

状态：0.7.0 已完成。

- 对 Series Archive 与重复 Category 提供只读的 canonical 风险提示；
- 检查 Rank Math 的 Series taxonomy title、description、robots 与 sitemap 配置；
- 检查搜索标题、社交标题与网页显示标题是否采用了预期变量；
- 不替 SEO 插件写入 metadata，也不自动删除 Category 关系。

实现说明：控制中心新增一个只读入口，逐个 Series 比较其公开、已发布、无密码
文章集合与 Category 集合；只有成员集合完全相同才标记为“重复入口”，部分重叠
只陈述共享数量。Rank Math 激活时，报告读取其当前 taxonomy title、description、
robots、sitemap、term canonical、Facebook／Twitter title 与文章默认 title template，
并标明 provider default、taxonomy setting、term override 与继承关系。Rank Math 未
激活时，数据库里残留的旧选项不会被当作当前配置；其他提供者或多个提供者会被
标为需要在原提供者和页面源码中确认，而不会猜测最终输出。

验收标准：报告能区分“没有配置”“重复入口”“由其他提供者接管”，所有建议
都需用户在原生 WordPress 或 SEO 插件界面确认后执行。

## 第五阶段：后台与主题适配完善

状态：0.8.0 已完成。

- Series 封面使用 WordPress 媒体库选择器，canonical 数据仍是原有附件 ID；
- 官方 Kadence 1.5.x 增加默认关闭的 Series Archive 卡片 Subtitle 适配，使用
  标题之后、元信息之前的精确 hook，不进入 Category／Search／Home；
- Classic Editor 增加受 nonce 与文章权限保护的 canonical 字段，仅在 Gutenberg
  实际关闭时出现，不创建 Series term，也不与区块编辑器重复；
- 继续以真实模板为准：本阶段扩充 Kadence Archive 适配与官方模板测试，未知
  经典主题仍使用手工区块、短代码或主题 API，不猜测标题槽。

实现说明：封面控件是渐进增强；若媒体脚本缺失，数字附件 ID 输入仍可使用且
旧值不会丢失。Classic Editor 对默认 `wptl_series` 提供单选关系；明确复用的
第三方 taxonomy 默认保留其原生控件，只有可信集成显式选择后才由本插件接管。
主题卡片 Subtitle 是 Reader 的独立开关，与结构化 Archive 的 Subtitle 开关
分离；切换到不支持的主题时保留设置但不输出。

验收标准：所有增强均可选；无适配时安全退回主题原有行为；编辑器不会出现
重复控件或因依赖缺失而失去原生编辑能力。官方 Kadence Archive 测试同时确认
Series 卡片可显示 Subtitle，而 Category 卡片、关闭开关与原生标题保持不变。

## 0.8.1 补丁阶段：多语言与本地化标点

状态：0.8.1 已完成。

- PHP 后台、Classic Editor、报告和前端标签统一使用插件 text domain；
- Gutenberg 侧栏接入 WordPress 的脚本翻译加载机制；
- 发布包内置完整简体中文翻译，英文作为源语言和无语言包时的降级语言；
- 发布 POT 目录，为其他语言保留 WordPress 社区及站点语言包入口；
- 冒号、破折号、竖线仍保存为模板语义，渲染时才按当前 locale 选择英文或
  中文字形；自定义分隔符不转换；
- 切换语言不改写文章、Series、term meta、迁移源数据或 `wptl_settings`。

验收标准：同一份设置在英文与简体中文间切换后，界面、枚举标签和内置标点
分别本地化，切回后完全恢复；自定义分隔符和所有 canonical 数据保持不变。

## 0.8.2 补丁阶段：正式站点验收修正

状态：0.8.2 已完成。

- 将 Archive 与后台报告分页改用独立的 Previous page / Next page 语义，中文
  显示为“上一页／下一页”；Series 阅读导航继续显示“上一篇／下一篇”；
- 将经验证的 Kadence Series Archive 卡片副标题从相对缩小字号提高为约
  17–19px 的响应式范围，避免小于正文；
- 后台顶级菜单使用可随 WordPress 管理配色重绘的自有标题层 SVG 图标；
- 插件作者显示名改为 Irisable，并补充项目网站 URI；
- 在不改变字段名、数据结构或保存逻辑的前提下，将共享设置页整理为带页内总览的
  响应式分区卡片；
- 不改变任何 canonical 数据、Reader 开关、Category 关系或 Series Archive
  布局策略。

验收标准：中文页码导航与文章导航用词不再混淆；Archive 卡片副标题在桌面和
手机上均可读；菜单图标在默认及其他管理配色下清楚可辨；设置职责容易定位且
手机无横向溢出；升级不写内容数据。

## 0.9.0 阶段：Category 协作与逐 Series 呈现策略

状态：0.9.0 已完成。

- Series 可选归属一个 Category，用于表达“内容领域之下的一条连续阅读路径”，
  但两个 taxonomy 不合并，也不自动增删文章 Category；
- 一致性按 Category 分支判断：文章只要属于归属 Category 或任一下级 Category
  即通过；不一致在 Gutenberg 和只读 Series Health 中提示，不阻止保存；
- 每个 Series 可继承或覆盖全站 Archive 布局、文章 Subtitle 与结构化列表特色图；
- 特色图全站默认关闭，旧 Series 缺少新 meta 时全部继承原设置；
- 结构化 Archive 可显示归属 Category 返回链接与紧凑响应式特色图；
- 共享设置页保留一个 Settings API 表单，无 JavaScript 时完整显示；脚本可用时
  渐进增强为支持方向键、Home／End 与 URL hash 的标签页。

验收标准：升级后旧 Series 前台零变化；逐 Series 覆盖只影响目标 Archive；
Category 不被自动改写；父／子 Category 被视为同一分支，无关 Category 被准确
提示；关闭 JavaScript 仍可看到并保存全部设置。

## 0.9.1 补丁阶段：可见层与后台细节收口

状态：0.9.1 已于正式站点通过人工验收。本版不改变 Series 顺序数据结构。

- 将 Structured Archive 分页调整为与 Kadence 协调的方形按钮：当前页实心，
  hover / focus 边框明确，前后页使用箭头，省略号不成为按钮，链接永不默认加下划线；
- Structured Archive 新增 Excerpt 全站默认和逐 Series 的继承／显示／隐藏策略，
  默认关闭，且不注入 Theme Archive、Category 或 Search；
- Series 可从媒体库选择一个图标附件，并配置全站默认及逐 Series
  继承／显示／隐藏。图标只作为 Single 题头中 Series 链接的装饰元素，
  不进入 H1、`post_title`、SEO 或社交标题；
- Gutenberg Title Layer block 和编辑面板使用与后台菜单一致的标题层图标，
  不再显示通用大写字母 A；
- 将现有 role 的“普通文章”与“文章”改为能说明其实际行为的文案：
  空值是“未指定（按主要文章处理）”，`article` 是“主要文章（明确指定）”。
  本版不为了消除文案重复而改写旧 meta。

验收标准：所有新开关默认不改变 0.9.0 前台；分页在桌面、手机和键盘
操作下均清楚；Excerpt 与图标只在已选定的位置输出；主题、Rank Math、
Category Archive 和普通文章不受影响。

实现说明：Excerpt 只在插件拥有的 Structured Archive view model 中按需读取；
Theme Archive adapter 未增加此通道。Series 图标保存为独立附件 ID，显示开关
为站点默认加逐 Series `inherit/show/hide`，渲染在 Series 链接内部且位于文章
H1 之外，使用空 `alt` 与装饰性语义。升级不会回填任何新 term meta，也不会
修改现有文章、篇序、Season key、Rank Math metadata 或模板选择。

## 0.10.0 阶段：Series Sequence Manager 与稳定 Season 身份

状态：0.10.0 已于正式站点通过人工验收；少数会破坏生产数据的故障注入项
转交 NAS 测试站验证。

- 新增正式 Sequence Manager，使有序 Series 支持拖动、键盘移动和“放在
  某篇之前／之后”；内部 rank 不出现在作者工作流中；
- 前台普通篇号由最终顺序自动计算，`wptl_sequence_label` 仍可逐篇覆盖；
- 为旧 Series 提供逐 Series 只读预览、冲突编排、冻结批次、分批初始化和
  值校验回退；仅激活新版不写旧 rank；
- 同一 canonical sequence service 同时驱动 Theme Archive、Structured Archive、
  Single 篇号、阅读导航、进度、Series Health 与 Manager；
- 新文章加入已管理的有序 Series 时可预期地追加在当前范围末尾，
  再从 Manager 移动；
- Season 定义增加稳定数字 `public_id`，新链接使用 `wptl_season=1`、
  `wptl_season=2`；旧 key URL 继续可达并引导到 canonical 数字 URL；
- 排序保存使用权限、nonce、成员再校验、revision 冲突检查、写前 journal
  和仅值匹配的撤销。

验收标准：在原第 21 与 22 篇之间插入一篇时，不手工改后续任何
文章即可得到连续前台篇号；所有顺序消费者一致；中断初始化不会产生
半迁移前台；并发编辑不互相覆盖；中文 Season 改名不改数字 URL。完整契约与
测试矩阵见 [Series Sequence Manager 设计](sequence-manager-design.md)，逐项实站
操作见 [0.10.0 人工验收清单](manual-acceptance-checklist-0.10.0.md)。

## 0.11.0 阶段：书籍式 Series 结构

状态：0.11.0 首轮 NAS 验收已通过；随后根据正式使用反馈补齐篇序／高级结构
职责分离和长列表搜索，修订候选正在做定向 NAS 回归。不要求在正式站点制造损坏
rank、删除 Season 或批量重排测试数据。

- 将篇目结构明确拆为 `scope`（Series / Season）与 `role`（序文 / 正文 /
  尾文 / 附录）；
- 有季 Series 允许不属于任何一季的 Series 级序文和尾文；正文仍必须
  有有效 Season；
- 每个 Series 或 Season 可有多篇序文、多篇尾文和附录，在各自轨道中使用
  0.10.0 的 rank 机制；
- 统一的“篇序与结构”页面包含两个视图：每个有序 Series 都可独立使用篇序管理，
  无须先启用书籍式结构；高级结构启用后再管理“Series 序文 → 各季序文／正文／
  尾文 → Series 尾文 → 附录”的轨道；
- 管理器 Series 选择器可搜索且保留原生下拉后备；Series Health 总览可按名称或别名搜索，
  筛选条件随分页保留；
- 有序 Series 的导航可纳入序文和尾文；无序 Series 仍不生成正文上一篇／
  下一篇，但 Structured Archive 可将序文和尾文固定在正文集合边缘；
- 季筛选页只显示该季的序文、正文与尾文；Series 级序文／尾文只出现在
  整个 Series Archive；
- 现有空 role 与 `article` 都兼容视为正文；`intro`、`epilogue`、
  `appendix` 先进入迁移预览，不在无法确定 scope 时自动猜测。

验收标准：平铺／多季、有序／无序的四种 Series 均保持自身语义；单篇与
多篇序文／尾文可表达；记号如 `0-1`、`0-2` 可用 Public label 稳定显示；
序文不挤占正文自动篇号；Archive、导航、进度、健康检查和 Manager 对 scope /
role 的理解一致。

## 1.0.0 候选：稳定契约，不再追加新模型

状态：0.9.1、0.10.0 与 0.11.0 已完成正式站点及隔离测试站验收；当前进入
`1.0.0-rc.1` 契约冻结、公开仓库和最终发布包验证。

- 冻结 Primary Title、Subtitle、Series、Season、Sequence、scope / role 和显示模板的
  数据契约；
- 完成升级、中断继续、撤销／降级预览、权限、并发、无 JavaScript、纯键盘、
  手机和长 Series 回归；
- 完成英文源文案、简体中文语言包和 POT 更新；
- 在受支持 WordPress / PHP 双矩阵、官方 Kadence、Rank Math，以及最终安装 ZIP
  上重复全套验证；
- 1.0.0 阶段只修正契约与体验缺口，不临时加入多 Series membership、
  任意模板代码或第二套排序模型。

0.10.1、0.11.1 等补丁号不预先塞入新功能；只在对应主版验收发现需要修正时使用。

## 持续保持的边界

- 一篇文章在当前模型中只属于一个主 Series；
- 不开放任意 HTML、PHP 或短代码模板；
- 不从旧 ACF Series-like 字段自动创建 Series；
- 不自动清除 Secondary Title／ACF 原数据；
- 不绕过 SEO 插件直接接管 document title、canonical 或 Open Graph。
