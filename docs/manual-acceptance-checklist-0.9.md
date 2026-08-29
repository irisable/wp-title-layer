# WP Title Layer 0.9.0 人工验收清单

这份清单只覆盖 0.9.0 新增或可能受其影响的正式站点行为。它不要求重做
0.4.0–0.8.2 已通过的全部迁移与前端验收。

## 一、升级基线

- [ ] 升级后打开一个未配置新字段的旧 Series，其 Single、Archive、导航与
  0.8.2 相同。
- [ ] 旧 Series 的 Archive layout、Article subtitles、Featured images 均显示
  Inherit；没有因升级自动写入新 term meta。
- [ ] 全站 Featured images 默认关闭，旧结构化 Archive 不会突然出现图片。

## 二、Category 与 Series 协作

- [ ] 给一个 Series 选择 Parent Category，保存并重新打开后仍为同一 Category。
- [ ] 该 Series 中一篇文章直接属于 Parent Category，编辑器不显示冲突提示。
- [ ] 另一篇文章只属于其下级 Category，仍被视为同一分支，不显示冲突提示。
- [ ] 让一篇文章只属于无关 Category，编辑器显示警告，但仍可保存或发布。
- [ ] 完成上述操作后，文章原有 Category 没有被插件自动添加、删除或替换。
- [ ] 清空 Parent Category 后，相关警告消失，Series 与文章 Category 均保持不变。
- [ ] 在 Series Health 选择 Category branch 视图，只有分支外文章被列出。

## 三、逐 Series Archive 策略

- [ ] 全站选择 Theme layout 后，仅把一个 Series 改为 Structured；只有该 Series
  使用插件结构化布局。
- [ ] 全站选择 Structured 后，仅把一个 Series 改为 Theme；该 Series 交还主题，
  其他 Series 保持结构化。
- [ ] 分别测试 Subtitle 的 Inherit、Show、Hide；只影响目标 Series Archive。
- [ ] 在结构化布局分别测试 Featured images 的 Inherit、Show、Hide；图片为紧凑
  响应式缩略图，缺图文章不留下空白占位。
- [ ] Theme layout 下的 Featured images 覆盖不改变主题自己的图片策略。
- [ ] 结构化 Archive 头部显示 Parent Category 链接，链接到正确的 Category。
- [ ] Category、Tag、Search、Home 及另一个 Series Archive 没有被目标 Series
  的覆盖值改变。

## 四、排序与结构化布局回归

- [ ] Ordered Series 仍按 Season → Position → ID 稳定排序。
- [ ] Unordered Series 的 date ascending、date descending、title 三种排序均生效。
- [ ] 桌面端无封面头部使用可用内容宽度；有封面时才进入两栏。
- [ ] 手机端标题、封面、特色图、Subtitle 与分页无横向溢出。
- [ ] Block theme 或存在专用 Series taxonomy 模板时仍由主题接管，不被插件替换。

## 五、设置页标签

- [ ] 设置页按 Placement、Presentation、Series reading、Search/sharing 等职责分栏。
- [ ] 点击标签只切换可见分区；保存后各模块设置均保持。
- [ ] 方向键、Home、End 可在标签间移动；浏览器地址 hash 随当前标签更新。
- [ ] 复制带 hash 的设置页地址重新打开，可回到相应标签。
- [ ] 临时禁用 JavaScript 后，所有分区同时可见，仍能使用同一个 Save Changes
  按钮正常保存。
- [ ] 在手机宽度下标签可用且设置字段无横向溢出。

## 六、多语言与权限

- [ ] 用户语言为简体中文时，新 Category、Archive 策略、特色图和标签页文字均为中文。
- [ ] 切换英文后显示英文，切换语言不改变任何已保存选项或关系。
- [ ] 无 Series 管理权限的用户不能修改 Series term meta；文章作者只能在已有
  Series 与 Category 权限范围内工作。

## 七、最终对照

- [ ] 普通非 Series 文章的标题、Subtitle、Archive 卡片与 SEO/分享标题无变化。
- [ ] 一个旧 Series、一个新建 Series、一个 Parent Category 分支外文章均已检查。
- [ ] Secondary Title／ACF 迁移页仍为只读扫描优先，新 Archive 与 Category 功能
  没有触碰旧源数据。
- [ ] 若使用 Kadence，至少复核一个 Series Archive 和一个 Category Archive；
  逐 Series Subtitle 覆盖只出现在预期的 Series 页面。
