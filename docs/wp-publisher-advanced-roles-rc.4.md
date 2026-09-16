# WP Title Layer / WP Publisher：高级角色协作契约（rc.4）

本次保留既有后台角色、范围、篇序和季结构，仅修正显示及客户端能力发现。

## 系列能力

原生 REST 系列条目（列表与单项）新增顶层只读字段：

```json
{"wptl_capabilities":{"version":1,"book_structure":true,"content_groups":"article-only"}}
```

`book_structure` 根据该系列实际启用状态计算；客户端不能写入。只有明确识别
`version === 1` 且 `book_structure === true` 时才可发布高级角色。缺失、未知版本或
禁用状态不能从私有标记推测；历史角色仍可读取和展示。

## 文章字段（不改存储结构）

| Obsidian | WordPress meta | 值 |
| --- | --- | --- |
| seriesRole | wptl_series_role | article / intro / epilogue / appendix |
| seriesScope | wptl_series_scope | series / season；主要文章不设置 |
| seriesLabel | wptl_sequence_label | 单篇公开标签，如 前言、序一、序二 |
| seriesGroup | wptl_series_group | 仅主要文章生效 |

季依旧由公开季编号解析为 `wptl_season_key`；全系列范围不得同时带季。
多季非正文的历史缺省范围不自动迁移，要求用户明确选择。普通文章保持原有季规则。

非正文隐藏分组编辑，Publisher 省略分组写入。WPTL 保留旧文本但不显示、不参与分组
筛选，切回主要文章后恢复。不要因角色切换发送空字符串或抹掉 Obsidian 旧属性。
客户端重新选择同一系列时，不重置已有角色、范围或公开标签。

## 前台显示

全系列 intro / epilogue / appendix 分别合并为“系列序文／系列后记／系列附录”栏目，
空栏目不显示。单篇公开标签显示在文章行，不再升为与季名同级的标题。
季内继续只使用季名作为栏目标题；排序逻辑和主要文章编号不变。

## 联调边界

两项目直接共享此契约和实际请求夹具。测试角色发布、读回、旧版能力回退、同系列
重新选择、范围切换、非正文分组保留与恢复。安装和正式发布各自独立，本次不推送、
不部署正式站，不覆盖 Publisher 已有未提交开发成果。
