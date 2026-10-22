# samples_test — 脱敏测试样本 / Sample test fixtures

结构与财务部真实导出文件完全一致，内容（户名、金额、客户名、账号）全部为虚构。可放入仓库，供开发与自动化测试使用。
真实文件绝不进仓库。

| 文件 | 来源 | 用途 |
|---|---|---|
| BCA_CorpAcctTrxn_SAMPLE.csv | KlikBCA Bisnis → Mutasi Rekening 导出 | FA-AI-01 BCA 解析器 |
| ICBC_currentaccounthistory_SAMPLE.csv | ICBC Indonesia 网银导出（制表符分隔） | FA-AI-01 ICBC 解析器 |
| Finance_Workbook_SAMPLE.xlsx | 财务现用工作簿（Buku Bank / Catatan Penerimaan / Pajak Keluaran） | 历史数据导入、状态口径 |
| Coretax_Faktur_Template_v1.6.1_SAMPLE.xlsx | DJP Coretax 销项发票导入模板 | 税务：平台需生成的目标格式 |
