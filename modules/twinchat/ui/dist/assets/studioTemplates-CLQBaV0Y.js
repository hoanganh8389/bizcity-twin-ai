function T(h,i){const n=(h??"").trim();if(!i)return n;const u=(i.prefix??"").trim(),c=(i.suffix??"").trim(),e=[];return u&&e.push(u),n&&e.push(n),c&&e.push(c),e.join(`

`)}function H(h,i){if(!h)return"";const n=[];n.push(`Tạo ảnh theo mẫu **${h.title}**.`),h.desc&&n.push(`(Mô tả mẫu: ${h.desc.trim()})`);const u=(i.notebookTitle??"").trim();u&&(n.push(""),n.push(`Chủ thể / bối cảnh: ${u}.`));const c=(i.summaryText??"").trim().replace(/\s+/g," ");if(c){const t=c.length>600?c.slice(0,600)+"…":c;n.push(""),n.push("Ngữ cảnh tổng hợp từ notebook:"),n.push(t)}const e=(i.skeletonThesis??"").trim(),o=(i.skeletonOutline??[]).filter(t=>t.label),a=(i.skeletonKeyPoints??[]).filter(Boolean).slice(0,5);(e||o.length>0||a.length>0)&&(n.push(""),n.push("Cấu trúc tài liệu đã phân tích (skeleton):"),e&&n.push(`Luận đề: ${e}`),o.length>0&&(n.push("Dàn ý:"),o.slice(0,8).forEach((t,s)=>{n.push(`  ${s+1}. ${t.label}${t.summary?` — ${t.summary}`:""}`)})),a.length>0&&n.push(`Điểm chính: ${a.join(" · ")}`));const r=(i.keywords??[]).filter(Boolean).slice(0,8);r.length>0&&(n.push(""),n.push(`Từ khoá cốt lõi: ${r.join(", ")}.`));const g=(i.pinnedNotes??[]).slice().sort((t,s)=>(s.is_starred??0)-(t.is_starred??0)).slice(0,3).map(t=>{const s=(t.title??"").trim()||"Ghi chú",p=(t.content??"").trim().replace(/\s+/g," ").slice(0,240);return p?`• ${s}: ${p}`:`• ${s}`});g.length>0&&(n.push(""),n.push("Ràng buộc / phải có (từ ghi chú đã ghim):"),n.push(...g));const d=(h.suffix??"").trim();return d&&(n.push(""),n.push(d)),n.join(`
`).trim()}const l=[{slug:"business-report-q",kind:"document",title:"Báo cáo kinh doanh",desc:"Báo cáo tổng kết kinh doanh Quý 1/2026 của Công ty Cổ phần BizCity…",icon:"📊",prefix:"Hãy soạn một BÁO CÁO KINH DOANH chuyên nghiệp dựa trên yêu cầu sau của người dùng:",suffix:`Yêu cầu format:
• Có Executive Summary đầu báo cáo
• Mục lục rõ ràng
• Bảng KPI / số liệu chính (markdown table)
• Phân tích nguyên nhân tăng/giảm
• Đề xuất action items
• Kết luận ngắn gọn 3 ý`},{slug:"project-proposal",kind:"document",title:"Đề xuất dự án",desc:"Đề xuất triển khai hệ thống CRM tích hợp AI cho Công ty ABC…",icon:"📝",prefix:"Hãy soạn một ĐỀ XUẤT DỰ ÁN (project proposal) thuyết phục dựa trên yêu cầu sau:",suffix:`Yêu cầu format:
• Bối cảnh & vấn đề
• Giải pháp đề xuất
• Phạm vi (in/out scope)
• Lộ trình 3 phases với milestone
• Ngân sách ước tính (bảng)
• Rủi ro & biện pháp
• ROI / lợi ích kỳ vọng`},{slug:"business-plan",kind:"document",title:"Kế hoạch kinh doanh",desc:"Kế hoạch kinh doanh năm 2026 cho startup EdTech tại Việt Nam…",icon:"📂",prefix:"Hãy soạn một KẾ HOẠCH KINH DOANH năm cho yêu cầu sau:",suffix:`Yêu cầu format:
• Tóm tắt điều hành
• Phân tích thị trường (TAM/SAM/SOM)
• Mô hình kinh doanh
• Kế hoạch sản phẩm
• Kế hoạch marketing & sales
• Tổ chức nhân sự
• Tài chính 12 tháng (bảng)
• KPI & milestones`},{slug:"service-contract",kind:"document",title:"Hợp đồng dịch vụ",desc:"Hợp đồng cung cấp dịch vụ phát triển phần mềm giữa Công ty TNHH…",icon:"📄",prefix:"Hãy soạn HỢP ĐỒNG DỊCH VỤ chuẩn pháp lý Việt Nam cho yêu cầu sau:",suffix:`Yêu cầu format:
• Đầy đủ điều khoản: bên A/B, đối tượng, phạm vi, giá trị, tiến độ thanh toán, bàn giao, bảo hành, vi phạm, bất khả kháng, giải quyết tranh chấp, hiệu lực
• Có chỗ ký tên đóng dấu cuối hợp đồng`},{slug:"case-study",kind:"document",title:"Bài tập tình huống",desc:"Bài tập tình huống về quản trị doanh nghiệp: Công ty XYZ đang đối mặt…",icon:"🎓",prefix:"Hãy soạn BÀI TẬP TÌNH HUỐNG (case study) cho học viên dựa trên yêu cầu sau:",suffix:`Yêu cầu format:
• Bối cảnh tình huống chi tiết, có nhân vật
• Dữ liệu nền (số liệu, biểu đồ mô tả)
• 4-6 câu hỏi thảo luận theo độ khó tăng dần
• Hướng dẫn giảng viên (gợi ý đáp án) đặt cuối, có heading rõ`},{slug:"meeting-minutes",kind:"document",title:"Biên bản họp",desc:"Biên bản cuộc họp Ban Giám đốc tháng 4/2026 của Công ty BizCity…",icon:"📋",prefix:"Hãy soạn BIÊN BẢN HỌP chính thức cho cuộc họp sau:",suffix:`Yêu cầu format:
• Header: thời gian, địa điểm, chủ trì, thành phần dự, vắng mặt
• Nội dung họp theo từng agenda item, có ghi rõ người phát biểu
• Quyết định / kết luận của chủ trì
• Action items: ai, làm gì, deadline (bảng)
• Chữ ký xác nhận`}],m=[{slug:"pitch-startup",kind:"presentation",title:"Pitch startup",desc:"Pitch deck cho startup AI SaaS Platform tại Việt Nam. Problem → …",icon:"🚀",prefix:"Hãy soạn PITCH DECK gọi vốn cho startup theo yêu cầu sau:",suffix:`Yêu cầu cấu trúc slide:
1) Title slide
2) Problem
3) Solution
4) Market size (TAM/SAM/SOM)
5) Product demo (mô tả screenshot)
6) Business model
7) Traction
8) Competition
9) Team
10) Ask & use of funds
Mỗi slide ≤ 6 bullet, có speaker notes 2-3 câu.`},{slug:"quarterly-report-deck",kind:"presentation",title:"Báo cáo quý",desc:"Báo cáo kết quả kinh doanh Quý 1/2026 cho Ban Giám đốc…",icon:"📈",prefix:"Hãy soạn BÁO CÁO QUÝ dạng slide cho cấp quản lý theo yêu cầu sau:",suffix:`Yêu cầu cấu trúc slide:
1) Tóm tắt
2) KPI dashboard (mô tả biểu đồ)
3) Doanh thu vs target
4) Phân tích kênh / sản phẩm
5) Highlights & lowlights
6) Insights
7) Plan quý sau
8) Q&A
Mỗi slide có speaker notes ngắn.`},{slug:"training-deck",kind:"presentation",title:"Đào tạo nhân viên",desc:"Chương trình đào tạo nâng cao kỹ năng bán hàng B2B cho đội sales mới…",icon:"🎓",prefix:"Hãy soạn CHƯƠNG TRÌNH ĐÀO TẠO dạng slide cho nhân viên theo yêu cầu sau:",suffix:`Yêu cầu cấu trúc slide:
1) Welcome
2) Mục tiêu khóa học
3) Agenda
4-N) Nội dung từng module — mỗi module: lý thuyết → ví dụ → bài tập
• Slide cuối: tóm tắt + tài liệu tham khảo
Thêm interactive question giữa các module.`}],f=[{slug:"sales-tracker",kind:"spreadsheet",title:"Theo dõi doanh số",desc:"Bảng theo dõi doanh số theo tháng, theo sales rep, có công thức tổng…",icon:"💰",prefix:"Hãy thiết kế BẢNG TÍNH theo dõi doanh số dựa trên yêu cầu sau:",suffix:`Yêu cầu cấu trúc:
• Sheet 1: Raw data (Date, Sales rep, Product, Qty, Revenue)
• Sheet 2: Pivot tổng hợp tháng × rep
• Sheet 3: Dashboard KPI với biểu đồ
• Có công thức SUMIFS/AVERAGEIFS
• Format conditional cho cell vượt/thiếu target`},{slug:"budget-plan",kind:"spreadsheet",title:"Kế hoạch ngân sách",desc:"Kế hoạch ngân sách 12 tháng theo phòng ban, so sánh kế hoạch vs thực tế…",icon:"📊",prefix:"Hãy thiết kế BẢNG NGÂN SÁCH 12 tháng dựa trên yêu cầu sau:",suffix:`Yêu cầu cấu trúc:
• Cột: 12 tháng × (Plan / Actual / Variance)
• Hàng: từng khoản chi theo phòng ban
• Subtotal mỗi phòng ban
• Total cuối
• Conditional format đỏ nếu Variance > 10%`},{slug:"project-tracker",kind:"spreadsheet",title:"Quản lý dự án",desc:"Bảng quản lý task dự án Gantt-style, có dependency, % hoàn thành…",icon:"📅",prefix:"Hãy thiết kế BẢNG QUẢN LÝ DỰ ÁN dựa trên yêu cầu sau:",suffix:`Yêu cầu cấu trúc:
• Cột: Task / Owner / Start / End / Duration / % Done / Status / Dependency
• Có sheet Gantt mô tả timeline
• Status auto: Not started / In progress / Done / Blocked
• Highlight task quá hạn`}],k=[{slug:"mindmap-strategy",kind:"mindmap",title:"Chiến lược kinh doanh",desc:"Sơ đồ tư duy chiến lược kinh doanh: visions, OKR, initiatives…",icon:"🎯",prefix:"Hãy vẽ SƠ ĐỒ TƯ DUY (mindmap) cho chiến lược kinh doanh theo yêu cầu sau:",suffix:"Yêu cầu format mermaid mindmap:\n• Root = vision\n• Nhánh cấp 1: 4-6 trụ cột chiến lược\n• Mỗi trụ cột: 2-4 OKR\n• Mỗi OKR: 2-3 initiatives cụ thể\n• Output: ```mermaid\\nmindmap\\n  root((vision))\\n```"},{slug:"mindmap-knowledge",kind:"mindmap",title:"Sơ đồ kiến thức",desc:"Sơ đồ tư duy hệ thống hoá một chủ đề học tập, từ tổng quan đến chi tiết…",icon:"🧠",prefix:"Hãy vẽ SƠ ĐỒ TƯ DUY hệ thống hoá kiến thức theo yêu cầu sau:",suffix:`Yêu cầu format mermaid mindmap:
• Root = chủ đề chính
• Cấp 1: các khái niệm con (4-7 nhánh)
• Cấp 2: định nghĩa / ví dụ / công thức
• Cấp 3: liên hệ thực tế hoặc bài tập áp dụng`},{slug:"mindmap-fishbone",kind:"mindmap",title:"Phân tích nguyên nhân",desc:"Sơ đồ Ishikawa / Fishbone để phân tích nguyên nhân gốc rễ của vấn đề…",icon:"🔍",prefix:"Hãy vẽ SƠ ĐỒ FISHBONE phân tích nguyên nhân theo yêu cầu sau:",suffix:`Yêu cầu format mermaid:
• Đầu cá = vấn đề / hiện tượng
• 6M xương chính: Man / Machine / Material / Method / Measurement / Environment
• Mỗi xương: 2-4 nguyên nhân con cụ thể
• Khoanh tròn root cause khả nghi nhất`}],y=[{slug:"img-infographic",kind:"image",title:"Infographic phẳng",desc:"Infographic flat-style 3 cột, màu tươi, icon đơn giản…",icon:"📊",prefix:"Tạo INFOGRAPHIC FLAT-STYLE chuyên nghiệp theo yêu cầu sau:",suffix:`Yêu cầu phong cách:
• Layout 3 cột rõ ràng, có heading lớn
• Bảng màu hài hoà 3-4 màu
• Icon line-art tối giản
• Chữ tiếng Việt rõ, font sans-serif
• Margin trắng đầy đủ, không chen chúc`},{slug:"img-poster",kind:"image",title:"Poster sự kiện",desc:"Poster cho sự kiện / khoá học, tỉ lệ A2 dọc, có headline + CTA…",icon:"🪧",prefix:"Tạo POSTER SỰ KIỆN ấn tượng theo yêu cầu sau:",suffix:`Yêu cầu phong cách:
• Headline lớn ở 1/3 trên
• Subtext giải thích
• Hình minh hoạ chiếm 1/2 dưới
• Footer: thông tin sự kiện (ngày, địa điểm, CTA)
• Tỉ lệ dọc, in được khổ A2`},{slug:"img-hero",kind:"image",title:"Hero banner",desc:"Hero banner website 16:9, có nhân vật + sản phẩm, nền gradient…",icon:"🖼️",prefix:"Tạo HERO BANNER website hiện đại theo yêu cầu sau:",suffix:`Yêu cầu phong cách:
• Tỉ lệ 16:9, độ phân giải cao
• Composition rule of thirds
• Có chỗ trống bên trái/phải để overlay text headline
• Bảng màu doanh nghiệp (xanh navy + accent vàng)
• Ánh sáng cinematic`}];function b(h){switch(h){case"document":return l;case"presentation":return m;case"spreadsheet":return f;case"mindmap":return k;case"image":return y;default:return[]}}export{y as I,T as a,H as c,b as g};
