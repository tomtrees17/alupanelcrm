# WhatsApp 群聊导出 · 脱敏样本（HR-04 v3）

`_chat.txt`：iPhone 导出格式（已按真实导出核对）。要点：
- 行首 `[M/D/YY, h:mm:ss AM/PM] 发送者: 内容`；发送者是已存联系人的名字或 `+62 8xx-xxxx-xxxx`
- 媒体：带媒体导出为 `<attached: 00000012-PHOTO-2026-10-19-16-50-39.jpg>`，不带媒体为 `<image omitted>`；相册先出现一行 `<album message>`
- 一次拜访 = 带说明文字的那张图；紧随其后同一发送者、同一分钟、无文字的图属于同一次拜访
- 多行说明无行首前缀，续行直到下一条 `[` 开头
- `This message was deleted` 忽略
- 真实导出在文本开头可能带 U+200E 不可见字符，解析前去掉

`_chat_android_variant.txt`：Android 导出格式片段，解析器要同时支持：`DD/MM/YY HH.MM - 发送者: 内容`，媒体为 `IMG-….jpg (file attached)` 或 `<Media tidak disertakan>` / `<Media omitted>`。

内容全部虚构。真实导出不进仓库。
