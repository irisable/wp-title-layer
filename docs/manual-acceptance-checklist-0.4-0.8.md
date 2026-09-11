# WP Title Layer 0.4.0–0.8.2 正式站点人工验收清单

这份清单用于验证从 0.4.0 到 0.8.2 新增的功能，并对标题、Subtitle、
Series、Archive、导航和 SEO 渠道做一次必要回归。自动化测试已经覆盖代码契约；
这里重点检查真实数据、真实主题、缓存、桌面与手机上的最终效果。

建议先用少量样本完成整套验证，不要一开始就批量修改所有 Series 或 SEO 模板。

## 一、测试记录

- [ ] 测试站点：____________________

- [ ] WP Title Layer 版本：____________________

- [ ] WordPress / PHP 版本：____________________

- [ ] 当前主题及版本：____________________

- [ ] 是否使用子主题：是 / 否

- [ ] 编辑器：Gutenberg / Classic Editor / 混用

- [ ] SEO 插件及版本：____________________

- [ ] 页面缓存 / CDN：____________________

- [ ] 测试日期与测试人：____________________

结果建议统一记为：`通过`、`失败`、`不适用`。失败时同时记录页面 URL、文章或
Series ID、桌面/手机环境和截图。

## 二、先准备一组小样本

尽量使用现有内容；需要制造异常时，优先用草稿，不要故意破坏正式文章。

- [ ] A：一篇无 Subtitle、无 Series 的普通已发布文章，作为对照。

- [ ] B：一篇有 Subtitle、无 Series 的已发布文章。

- [ ] C：一个无序、无季 Series，含至少 3 篇已发布文章和 1 篇草稿。

- [ ] D：一个有序、无季 Series，含系列首篇、中间篇、末篇。

- [ ] E：一个有序、有季 Series，至少 2 季，每季至少 2–3 篇文章。

- [ ] F：一篇草稿或待审文章，用于测试制作阶段提示。

- [ ] G：一篇私密、定时或密码保护文章，用于检查前台隔离。

- [ ] H：一个与 Series 公开文章集合完全相同的 Category。

- [ ] I：一个只与 Series 部分重叠的 Category。

- [ ] J：一篇从 Secondary Title 迁移而来的旧文章，确认旧数据仍可正常显示。

## 三、升级与基础回归（P0，首先完成）

- [ ] 升级后后台和前台均无白屏、严重错误或持续报错通知。

- [ ] 插件页面显示预期版本，设置、Series、文章关系和 Subtitle 均仍存在。

- [ ] 旧 Secondary Title 插件保持停用；WP Title Layer 未重新改写或清除旧源数据。

- [ ] 抽查 A、B、J：原生标题没有变化，已有 Subtitle 没有丢失或重复。

- [ ] 单篇文章只有一个主标题 H1；正文里没有多出第二套 Title Layer。

- [ ] 菜单、面包屑、相关文章、Category、Search 和首页标题没有被全局替换。

- [ ] 草稿、私密、定时和密码保护文章的信息没有出现在公开导航或公开列表中。

- [ ] 未设置的新功能保持保守默认值，没有因升级突然改变全站前台。

以上任何一项失败，先停止后续批量配置。

## 四、0.4.0：Series 生命周期状态

分别测试 `未设置`、`筹备中`、`连载中`、`暂停`、`已完结`。

- [ ] 在新建和编辑 Series 页面可以选择、保存、修改及清除状态。

- [ ] 刷新编辑页后，已保存状态仍正确。

- [ ] Series 后台列表显示正确状态。

- [ ] 旧 Series 默认仍为“未设置”，插件没有根据日期或篇数自动猜测。

- [ ] 使用 Structured Series layout 时，已设置状态显示轻量标签；未设置时不显示空标签。

- [ ] 使用 Theme archive layout 时，未出现插件强行插入或破坏主题布局的状态元素。

- [ ] 改变生命周期状态不会发布、隐藏、锁定、重排文章，也不会改变上一篇/下一篇。

## 五、0.5.0：Series Health 只读检查

入口：`WP Title Layer → Series Health`。

- [ ] 选择无序 Series C，不会误报“缺少篇序”。

- [ ] 选择有序 Series D，正常文章的篇序和统计与实际一致。

- [ ] 用草稿制造一个缺篇序，报告能发现并将其列为制作阶段提示。

- [ ] 用草稿制造重复篇序，报告能列出冲突文章并提供正确编辑链接。

- [ ] 在有季 Series E 中测试缺季、未定义季、缺篇序和重复篇序。

- [ ] 已发布、定时、私密、草稿、待审、回收站和密码保护状态被正确区分。

- [ ] 点击异常文章链接进入的是正确文章，且无编辑权限者不会获得越权入口。

- [ ] 反复打开、翻页或刷新报告，不会修改文章、篇序、季、状态或 Series 关系。

- [ ] 若某 Series 超过 50 篇，分页后统计、文章数量和异常项没有漏失或重复。

## 六、0.6.0：有季 Series 的阅读导航范围

在 Series E 上依次测试“全系列”和“当前季”。Reader 的自动文末导航需先在设置中
启用；也可用 `[wptl_series_navigation]` 单独验证。

### 全系列

- [ ] 第一季末篇的“下一篇”进入第二季首篇。

- [ ] 第二季首篇的“上一篇”回到第一季末篇。

- [ ] 系列首篇没有错误的上一篇；系列末篇没有错误的下一篇。

- [ ] “从系列开头”、当前位置和总数都按整个公开 Series 计算。

### 当前季

- [ ] 第一季末篇不跨到第二季。

- [ ] 第二季首篇不回到第一季。

- [ ] “从本季开头”、当前位置和总数只计算当前季。

- [ ] 标题题头中的季名链接进入本季过滤列表，而不是无筛选的 Series 首页。

- [ ] 季链接、Series 链接、篇序文字的范围和位置正确；篇序本身不是错误链接。

### 安全边界

- [ ] 草稿、私密、定时、密码保护和回收站文章均不进入公开上一篇/下一篇及总数。

- [ ] “当前季”文章缺季或季无效时，导航安全地不输出，不会猜测到其他季。

- [ ] Flat Series 即使保存了异常季范围值，仍按整个 Series 处理。

- [ ] 无序 Series 不出现阅读进度、上一篇/下一篇或伪造的阅读顺序。

- [ ] 文末导航链接默认无下划线，鼠标悬停或键盘聚焦时才出现清楚的颜色/下划线反馈。

## 七、0.7.0：Landing & Channels 与 SEO 渠道

入口：`WP Title Layer → Landing & Channels`。这一页应当只报告，不替你修改
Category、canonical、robots、sitemap 或 SEO 标题。

### Landing / Category 重复入口

- [ ] Series 与 Category 公开文章集合完全一致时，报告标为 exact duplicate。

- [ ] 只部分重叠时，报告只显示 shared count，不误判为完全重复。

- [ ] 草稿、私密、定时和密码保护文章不参与公开集合比较。

- [ ] 报告中的 Series 和 Category 链接都进入正确公开页面。

- [ ] 打开和刷新报告不会删除 Category 关系，也不会自动设置 canonical。

### Rank Math（未启用时记为“不适用”）

- [ ] 报告能区分 provider default、taxonomy setting、term override 与继承。

- [ ] Series taxonomy 的 title、description、robots 和 sitemap 状态与 Rank Math 后台一致。

- [ ] 单个 Series 的 canonical、robots、Facebook title、Twitter title 和 sitemap exclusion 与实际设置一致。

- [ ] 没有单独设置社交标题时，报告正确说明其继承 SEO title。

- [ ] Rank Math 停用时，旧数据库选项不会被当成当前有效配置。

- [ ] 若由其他 SEO 插件接管，报告提示回到该插件和页面源码确认，而不是猜测结果。

### 变量小样本

只选择 1–2 篇文章试用，不要先改全站默认模板。

- [ ] `%wptl_subtitle%` 只输出 Subtitle；无 Subtitle 时为空。

- [ ] `%wptl_series%` 在文章和 Series Archive 上输出正确 Series 名称。

- [ ] `%wptl_title_with_subtitle%` 输出“标题：副标题”；无 Subtitle 时没有悬空冒号。

- [ ] 搜索预览、浏览器页面源码和实际分享卡片结果一致。

- [ ] WP Title Layer 没有自行写入 canonical、robots、Open Graph 或 Twitter metadata。

## 八、0.8.0：后台与主题适配

### Series 封面

- [ ] 在 Series 新建/编辑页可从媒体库选择封面。

- [ ] 保存并刷新后封面仍正确；更换和移除均正常。

- [ ] 媒体库不可用时，数字附件 ID 的降级输入仍可用，旧值不会丢失。

- [ ] 无效 ID 或非图片附件不会造成白屏或破坏 Archive。

- [ ] Structured Series layout 中封面、标题、说明和内容在桌面/手机上布局正常。

### Gutenberg 编辑器

- [ ] WP Title Layer 侧栏可以搜索并选择现有 Series，包括尚未关联文章的 Series。

- [ ] 保存、刷新、改选及清除 Series 后，关系均正确持久化。

- [ ] 原生 Tags 式 Series 面板没有与插件面板重复出现，也没有多余的 `Add New Series`。

- [ ] Subtitle 输入框、Template、季、篇序、角色等字段按当前 Series 模式正确显隐。

- [ ] 无序 Series 不要求篇序；有序 Series 的必要字段验证正确。

### Classic Editor（实际使用时测试）

- [ ] 只有在 Gutenberg 确实关闭时才出现 WP Title Layer 元框。

- [ ] 可以保存和清除 Subtitle，并从现有 Series 中单选一个关系。

- [ ] 有序/有季字段按 Series 配置出现并保存到与 Gutenberg 相同的数据。

- [ ] Classic Editor 元框不能创建新的 Series term。

- [ ] 自动保存、修订或权限不足时，不会误写或清空 Title Layer 数据。

- [ ] 切回 Gutenberg 后不出现两套重复控件。

### Kadence Archive 卡片 Subtitle（仅官方受支持版本）

- [ ] 此开关升级后默认关闭，关闭时页面与主题原结果一致。

- [ ] 打开后，只在 Series Archive 卡片的标题之后、元信息之前显示 Subtitle。

- [ ] Category、Search、Home 和其他 Query Loop 不显示该适配器插入的 Subtitle。

- [ ] 无 Subtitle 的卡片不留下空行或异常间距。

- [ ] 卡片主标题和链接仍由 Kadence 正常输出，没有重复标题或额外 H1。

- [ ] 关闭开关后 Subtitle 立即消失，文章数据本身不受影响。

- [ ] 若使用覆盖相关模板的 Kadence 子主题，插件安全回退，不强行猜测插入位置。

### 未知或不支持的经典主题

- [ ] 插件不自动接管未知标题槽，也不向所有 Archive 卡片全局注入 Subtitle。

- [ ] 手工区块、短代码或主题 API 仍能按既有方式工作。

## 九、0.8.1：简体中文与本地化标点

先记录当前 `设置 → 常规 → 站点语言`，以及测试管理员在个人资料中的界面
语言。后台优先按用户语言显示，前台按当前页面请求语言显示，两者可以不同。

- [ ] 管理员个人语言为 English 时，WP Title Layer 控制中心、设置页、Series
  编辑页、报告页和 Classic Editor 元框（若使用）均显示英文。

- [ ] 管理员个人语言改为“简体中文”并重新登录/刷新后，上述插件自有界面
  显示简体中文；Gutenberg 右侧 WP Title Layer 面板也同步变为中文。

- [ ] Series 的 Ordered/Unordered、Flat/Seasoned、状态、归档排序、导航范围和
  文章角色不再残留由代码临时生成的英文标签。

- [ ] 英文前台使用 `: `、` — `、` | `；简体中文前台对应使用 `：`、`——`、
  `｜`。分别检查分行/同行 Title Layer 和 Rank Math 组合标题变量。

- [ ] 自定义分隔符（例如 `/`、`·` 或自定义短文本）在英文与中文间切换后
  完全不变，也没有被翻译或自动加空格。

- [ ] 切换语言前后，文章 Subtitle、Series 关系、季、篇序、模板选择和
  `wptl_settings` 均未被改写；切回原语言后显示恢复。

- [ ] 若同一页面仍出现“页头之上的背景图像”等由 Kadence、WordPress 或其他
  插件提供的文字，确认它属于对应组件的语言包，而不是 WP Title Layer 漏译。

- [ ] 暂无语言包的第三种语言安全降级为英文，不出现空按钮、原始键名或 PHP
  警告；后续可通过标准 WordPress PO/MO/脚本 JSON 方式补充翻译。

## 十、0.8.2：正式站点验收修正

- [ ] Structured Series Archive 有多页时，分页显示“上一页／下一页”，不再
  显示“上一篇／下一篇”。

- [ ] Series Health 与 Landing & Channels 有多页时也使用页码语义。

- [ ] 文末 Series 阅读导航仍然使用“上一篇／下一篇”，没有被改成页码语义。

- [ ] Kadence Series Archive 卡片 Subtitle 在手机和桌面均不小于正文，且仍
  明显低于卡片标题层级。

- [ ] WP Title Layer 后台顶级菜单显示自有标题层图标，不再是字母 A；在当前
  WordPress 管理配色及 hover/选中状态下均清楚可见。

- [ ] 插件列表作者显示为 Irisable，作者链接进入 `https://irisable.com/`。

- [ ] 设置页顶部按顺系列出前端放置、标题呈现、Series 阅读、搜索与分享等当前
  已注册分区；点击可到对应卡片，桌面与手机均无横向溢出。

- [ ] 设置页仍只有一个保存按钮；修改任一分区后，其他分区及 Reader 设置不会
  被清空或恢复默认。

- [ ] 升级没有改变现有 Subtitle、Series、Season、篇序、模板或 Reader 设置。

## 十一、当前完整功能的跨版本回归

这些功能不全是 0.4.0 后新增，但升级后必须抽查。

### 单篇标题层

- [ ] Standard、Editorial、Inline、Minimal 四套模板各检查一篇。

- [ ] Standard 与 Editorial 的视觉层级和使用场景确实可辨，而不是看起来完全相同。

- [ ] “短标题 + 同行 Subtitle + Series 题头”组合能同时成立，Subtitle 不进入 H1。

- [ ] 每套模板的 Series 显隐、Subtitle 分行/同行/隐藏和分隔符设置均生效。

- [ ] 自定义纯文本分隔符正常转义；HTML、短代码或脚本不会被执行。

- [ ] Subtitle 在手机上仍明显大于正文、略小于主标题，换行不过分拥挤。

- [ ] Series 名称链接进入 Series Archive；有季时季名进入本季列表。

- [ ] Series/季链接默认无下划线，鼠标悬停和键盘聚焦时反馈清楚。

### Archive

- [ ] Theme archive layout 与当前主题的 Category Archive 宽度、卡片和分页一致。

- [ ] 有序 Series 无论主题默认日期方向如何，均按 canonical 篇序显示。

- [ ] 无序 Series 的 oldest/newest/title 排序分别符合设置，ASC/DESC 没有被忽略。

- [ ] Structured Series layout 在桌面不挤在左侧，标题字号不过大，手机不横向溢出。

- [ ] 有封面和无封面两种 Structured 布局都检查；季分组、篇序和 Subtitle 均正确。

- [ ] Category、Tag、Search 和 Home 不被 Series Archive 的排序或模板设置影响。

### 后台便利性

- [ ] Series 新建页至少提供 4 行 Season；保存后可继续增加更多季。

- [ ] 文章列表的 Screen Options 可开启/关闭 Subtitle 列，内容与文章实际 Subtitle 一致。

- [ ] Series 列、Subtitle 列和编辑链接在窄屏后台没有明显覆盖或截断。

## 十二、桌面、手机、缓存与无障碍

至少分别用一个桌面宽度和一部手机实测，不只缩放桌面浏览器。

- [ ] 单篇 Title Layer 在桌面和手机均不溢出、重叠或贴边。

- [ ] 长标题、长 Subtitle、长 Series 名和中文/英文混排均能自然换行。

- [ ] Structured Archive、主题 Archive 和文末导航在两种尺寸下均可读。

- [ ] 链接可用键盘 Tab 聚焦，hover/focus 状态不仅依赖颜色，且不会永久显示下划线。

- [ ] 清理页面缓存/CDN 后，用退出登录窗口检查实际访客结果。

- [ ] 管理员登录状态与匿名访客看到的公开文章集合一致，不泄露非公开内容。

- [ ] 特殊字符、引号、破折号、竖线和中文标点显示正确，没有裸 HTML。

## 十三、发布阻断条件

出现以下任一情况，不建议继续扩大到全站：

- [ ] 后台或前台白屏、严重 PHP/JavaScript 错误。

- [ ] 标题消失、出现两个主 H1，或正文被标题接管逻辑改写。

- [ ] Subtitle、Series 关系、季、篇序或原迁移数据丢失。

- [ ] 草稿、私密、定时、密码保护文章进入公开 Archive、导航或计数。

- [ ] “当前季”导航跨季，或上一/下一/进度使用了不同文章集合。

- [ ] Kadence 卡片适配影响 Category、Search 或 Home。

- [ ] 只读报告实际改动 Category、canonical、robots、sitemap 或文章数据。

- [ ] 未经明确设置，SEO 标题或社交分享标题发生全站变化。

## 十四、推荐执行顺序

1. 先完成“升级与基础回归”，确认没有数据和标题结构问题。

2. 用 C、D、E 三个 Series 验证生命周期、Health 和两种导航范围。

3. 比较 Theme / Structured Archive，并在桌面和手机各看一次。

4. 若使用 Kadence，只给一个 Series 打开卡片 Subtitle，再检查 Category、Search、Home 对照页。

5. 打开 Landing & Channels 做只读核对；SEO 变量只在 1–2 篇样本上试验。

6. 最后清缓存并退出登录，重新检查公开单篇、Series Archive、季链接和文末导航。

## 十五、问题记录模板

每个问题建议记录：

- 功能位置：____________________

- 结果：失败 / 体验建议

- 页面 URL：____________________

- 文章 / Series ID：____________________

- 预期结果：____________________

- 实际结果：____________________

- 环境：桌面 / 手机；登录 / 访客；主题与版本：____________________

- 是否清缓存后复现：是 / 否

- 截图或录屏：____________________
