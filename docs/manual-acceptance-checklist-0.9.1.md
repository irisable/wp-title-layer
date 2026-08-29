# WP Title Layer 0.9.1 人工验收清单

本清单只验证 0.9.1 的新增行为及其高风险回归边界。0.10.0 的 Series
Sequence Manager、数字 Season URL 和书籍式序文／尾文结构尚未进入本版，
不应在本轮寻找或验收。

## 一、测试前记录

- [ ] 已备份数据库与当前 0.9.0 插件 ZIP，可在需要时恢复。
- [ ] 记录 WordPress、PHP、主题及 Rank Math 版本：____________________
- [ ] 准备一个普通非 Series 文章、一个平铺 Series 和一个至少两季的 Series。
- [ ] 为结构化归档准备两篇有手写摘要的文章和一篇只有正文、无手写摘要的文章。
- [ ] 记录升级前一个 Single、Theme Series Archive、Category Archive 和 Search
      页面，便于逐项对照。

## 二、升级兼容与默认值

- [ ] 从 0.9.0 覆盖安装 0.9.1 后，现有文章、Series、Season、篇序和 Category
      关系均未改变。
- [ ] 新增“结构化归档摘要”和“文章标题层中的 Series 图标”全站开关默认关闭。
- [ ] 未编辑过新字段的旧 Series 均显示“继承”，升级后前台与升级前一致。
- [ ] Secondary Title / ACF 迁移记录、源字段和既有 `wptl_subtitle` 没有被重写。

## 三、结构化归档分页

- [ ] 准备至少两页文章；桌面端页码为大小一致的方形按钮，当前页为实心。
- [ ] 上一页／下一页只显示 ‹ 和 ›；省略号没有方框，也不可点击。
- [ ] 默认、鼠标移入、键盘聚焦时，分页链接均不显示下划线。
- [ ] hover 有清楚的主题强调色／边框，Tab 聚焦有可见焦点轮廓。
- [ ] 手机宽度下按钮可以自然换行，不横向溢出，也不会挤成不可点击的小块。
- [ ] 中文读屏名称仍是“上一页／下一页”，文章末尾导航仍是“上一篇／下一篇”。

## 四、结构化归档 Excerpt

- [ ] 全站开关关闭且 Series 为“继承”时，结构化归档不显示文章摘要。
- [ ] 全站开启后，Series 为“继承”时显示 WordPress 摘要。
- [ ] 全站关闭 + Series“显示”可以单独开启；全站开启 + Series“隐藏”可以
      单独关闭。
- [ ] 手写摘要正确显示；无手写摘要时采用 WordPress 的自动摘要规则。
- [ ] 摘要中的 HTML 不作为可执行或结构化标记输出；脚本与短代码
      不会执行，危险属性不会进入页面结构。
- [ ] Subtitle、Excerpt 与特色图像三个开关互不清除、互不替代。
- [ ] Theme Series Archive、Category、Search 和 Home 不因结构化 Excerpt 开关
      增加摘要；原主题本来显示的摘要仍由主题控制。

## 五、Series 图标

- [ ] 新建／编辑 Series 时可从媒体库选择、替换和移除图标；Series 封面不受影响。
- [ ] 关闭 JavaScript 后，图标附件 ID 数字输入仍可读取和保存现有值。
- [ ] 全站关闭 + Series“继承”时不显示；Series“显示”可单独开启。
- [ ] 全站开启 + Series“继承”时显示；Series“隐藏”可单独关闭。
- [ ] 图标出现在 Single 题头的 Series 链接内，点击仍指向同一 Series Archive。
- [ ] 图标大小与文字协调，桌面和手机上不撑高题头、不挤压 Season／篇序。
- [ ] 页面源码中图标位于 H1 外，`alt=""`，且作为装饰内容不会被读屏重复朗读。
- [ ] 文章使用 Kicker override 时不会把该自定义文字误当成带图标的 Series 名。
- [ ] Standard、Editorial、Inline 以及已验证 Kadence 的两个标题位置均保持恰好
      一个预期 H1；Title only／隐藏 Series 的模板不出现图标。
- [ ] `%wptl_series%` 仍只返回完整 Series 文字；SEO 标题、Facebook／Twitter
      标题和分享卡片中没有图片 HTML、附件 URL 或图标替代文字。
- [ ] 普通非 Series 文章完全不受图标全站开关影响。

## 六、编辑器图标与 role 文案

- [ ] Gutenberg 右侧 WP Title Layer 面板显示插件自有标题层图标，不再是字母 A。
- [ ] 插入器中的 Title Layer 动态区块、占位提示和错误占位使用同一图标。
- [ ] Series role 的空值显示“未指定（按主要文章处理）”，显式 `article` 显示
      “主要文章（明确指定）”；英文界面能表达同样区别。
- [ ] 切换两种标签后保存、重载仍保持所选值；升级没有批量写入或转换旧 role meta。
- [ ] `intro`、`epilogue`、`appendix` 的既有选择与前台表现没有改变。

## 七、语言与设置保存

- [ ] 简体中文用户看到本清单涉及的全部新字段中文；英文用户看到英文源文案。
- [ ] 切换用户语言或站点语言不会改写 Excerpt、图标附件 ID、显示策略或文章内容。
- [ ] 在设置页只改变标题图标开关，不会清空 Reader、模板或 Category 规则。
- [ ] 在设置页只改变结构化 Excerpt 开关，不会清空标题呈现、Rank Math 或其他
      Reader 开关。
- [ ] 无 JavaScript 时所有设置分区和新字段仍可见，并由同一个“保存更改”提交。

## 八、关键回归

- [ ] Series／Season 链接、篇序、全系列／当前季导航和进度与 0.9.0 一致。
- [ ] Ordered / unordered、flat / seasoned 四种 Series 的 Archive 顺序没有变化。
- [ ] Theme Archive 与 Category Archive 仍继承当前主题布局；结构化模板只接管
      已明确选择且允许接管的 Series Archive。
- [ ] Kadence Archive 卡片 Subtitle 开关和逐 Series 覆盖仍正常，未进入 Category。
- [ ] Landing & Channels 仍为只读；Rank Math 变量、canonical、robots、sitemap
      和社交标题设置均未被插件自动写入。
- [ ] Classic Editor、文章列表 Subtitle 栏、Series Health 与迁移页面仍可正常使用。

## 九、验收记录

- 测试站点：____________________
- 测试日期：____________________
- 通过项：________ / ________
- 未测项及原因：________________________________________________________
- 发现的问题：__________________________________________________________
- 结论：[ ] 可部署　[ ] 修正后复测　[ ] 回退 0.9.0
