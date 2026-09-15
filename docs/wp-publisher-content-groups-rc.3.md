# WP Publisher：内容分组接入（WP Title Layer 1.0.0-rc.3）

## 最小改动

新增 Obsidian 属性：

```yaml
seriesGroup: "W1 看见人的软弱"
```

写入文章时，随原有系列关系、季字段一起提交：

```json
{
  "meta": {
    "wptl_season_key": "s1",
    "wptl_series_group": "W1 看见人的软弱"
  }
}
```

仍使用 `POST /wp/v2/posts` 或 `POST /wp/v2/posts/{id}`，自定义文章类型沿用
其已有 REST 路径。上述片段须合并到现有请求；不覆盖其他 metadata。
系列关系沿用现有实现。季键来自系列定义，不根据公开季编号猜测。

## 缺省与清空

| Obsidian 状态 | 写入行为 |
| --- | --- |
| 未声明 `seriesGroup` | 省略 `wptl_series_group`，保留 WordPress 已有值 |
| `seriesGroup: ""` | 发送空字符串，取消本文分组 |
| 非空字符串 | 写入组名，在当前系列与有效季范围内匹配或新建 |
| 数组、对象、布尔等非字符串 | 提示类型错误，不能转成字符串猜测 |

YAML 的 `seriesGroup:` 是 null，建议提醒用户使用明确的 `""` 清空，
不要把 null、未声明与清空混为一谈。

## 读回与兼容

- 读回使用 `response.meta.wptl_series_group`，该值是当前规范组名。
- WP Title Layer 的“修改此组名称”会统一改名并保留旧名别名；旧 Obsidian
  文件继续发送旧名时仍属于原组，REST 响应返回新名。Publisher 按已有受保护的
  frontmatter 回写规则更新，不能额外覆盖用户在发布期间的编辑。
- 新字段应按目标站的 REST schema 探测支持。旧版 WPTL 不支持该字段时，
  明确报告未同步分组，不得声称整篇属性全部同步完成；未使用分组的文章按原流程发布。
- 不读取、写入或推导 `_wptl_group_id`、内部 taxonomy、篇序 rank。
- 改季或改系列后，同名文本归入新范围的组；显式取消分组时仍应发送空字符串。
- 普通更新组名只改变本文归属。“统一改名”是另一个操作，不应由 Publisher
  因一篇文章的 frontmatter 改动自动触发。

## 可选建议接口

`GET /wp-title-layer/v1/groups?series={term_id}&season={internal_season_key}`

需要登录及系列分配权限；平铺或全系列范围传空的 season。
响应为 `[{"id":3,"label":"W1 看见人的软弱","aliases":[]}]`。
最小发布接入不需要调用此接口。WordPress 编辑器已有建议与统一改名入口。

## 联调检查

1. 新文章带组名发布；WordPress 题头显示“系列 · 季 · 组名 · 篇号”。
2. 同季第二篇使用同名，归档落入同一连续分段；下一组继续原篇号。
3. 省略属性再次发布，已有分组保留；空字符串再次发布，分组取消。
4. 相同名称位于不同季时，点击组名不混入另一季。
5. WordPress 统一改名后，旧链接有效；Publisher 用旧名再次发布不创建重复组。
6. 旧 WPTL 站点与未配置分组的文稿仍可按原流程发布。

本文件是接入约定；本项目只修改 WP Title Layer，尚未修改 WP Publisher 客户端。
