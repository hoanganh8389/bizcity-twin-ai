Với 1 tool , đặc biệt là tool mang tính chất được intent provider (automaiton provider) cung cấp thông tin. Phía tool chỉ cung cấp các required_slots hoặc optionl_slots cần thiết như nào.
Nhưng phía it_call_tool.php cần phải có 1 cơ chế để execute message gửi HIL với user để hỏi và gathering slots , cho đến khi fill đủ dữ liệu (required bắt buộc có)  và optional (bổ sung nếu có) để gửi tin nhắn confirm trước khi execution. 
Sau khi chạy xong, bắt buộc phải lưu kết quả hoàn thành theo tiêu chuẩn chung (trước đây đang là CPT dùng post và post meta để làm.
Điều này có 2 ý nghĩa:
1. Verify lại (execute message ) cho user biết kết quả và lưu CPT làm giá trị verify sau này. Cá nhân mình đang nghĩ, nên tận dụng bảng wp_xxx_intent_convesations để quy chuẩn id và các meta liên quan đến id intent đó , để đảm bảo đồng nhất với các nhiệm vụ phát hiện được trong các single step . NHư vậy sẽ ko bị phân mảnh dữ liệu. Quy chuẩn thành wp_xxx_intent_convesations  sẽ ok hơn. CPT hơi rườm rà, khó quản lý, bị lẫn với các CPT khác. phức tạp hơn.
2. bridge về mặt dữ liệu, meta đồng nhất, nhất quán, có tính chất ổn định, consistency để note tiếp theo có thể tận dụng các meta từ CPT đó để detect, review lại và planner tiến hành HIL tiếp các thông tin còn thiếu (Nếu  đủ rồi thì thôi) , nhưng vẫn tiếp tục lại vòng lặp HIL confirm và tiến hành tiếp. sau đó tiếp tục lặp lại.
3. Chúng ta luôn cần 1 node thêm vào ngay đầu sau instant run, 1 node action, có vai trò là to dos list.  Nếu thống nhất dùng theo chuẩn DB là lưu vào wp_xxx_intent_convesations  thì note action này có tác dùng list ra danh sách các note phải làm đằng sau. Làm đến node nào ok, hay cancel thì đều update tình trạng (status) là COMPELETED hay đang ACTIVE hay đã CANCEL.... 
Như vậy, thì có thể HIL tiếp và resume bất cứ lúc nào cần.
4. Note to dos này sẽ luôn bắt buộc phải có , sau trigger instant run, cũng như mỗi node it call tool khi verify lại sau khi thành công (goal) thì cũng đánh dấu goal để xác nhạn todos list này đã xong. Làm đến node nào xong, thì lại gửi 1 lần nữa todos này , LLM provider verify lại và đánh giá đã xong (tự self answer lại - reflection) và continue tiếp sang node khác pipeline phía sau.
5. Note verify tổng thể todos toàn bộ để summary lại toàn bộ chuỗi công việc đã làm cũng cần bổ sung và mặc định để ở dưới cùng, khi các công việc hoàn tât. 

Cứ như thế, thì cho dù có 1000 node , thì cũng tuần tự, độc lập, ko vướng.
Cơ chế resume sẽ dựa vào to-dos mà resume lại. Nếu vậy thì có chăng, chỉ cầnxem dựa trên các bảng chúng ta có rồi, xem có nên có thêm 1 bảng gọi là To-dos, lưu json, tình trạng, score điểm số để sau này học kih nghiệm , gia tăng kinh nghiệm , learning loop kinh nghiệm bằng bảng này. THêm 1 raw material về mặt experience cho AI ...
Update vào Phase 1.1 việc mỗi tool độc lập, rõ ràng và tự chủ như này.

Các tool đựoc mô tả trong yêu cầu có tính chất:
1. Planner => To-dos.
2. Wrapper thêm các layer HIL và Verify (xác nhận to-dos done hay ko done)
3. Verifier all plan.

Sẽ luôn mặc định có trong pipeline. 
Các nhóm tool  trong modules/workfow/blocks sẽ luôn là các block built-in ưu tiên sencondary là sản xuất content, hình ảnh, nội dung, tiêu đề .... kiểu creative.

Các nhóm tool trong các plugins sẽ ưu tiên thứ 3, chạy phía sau.

Các maintool trong các plugin luôn cần bọc (wrapper) thêm các lớp được mô tả trong file này để đảm bảo chạy độc lập , rõ ràng, tự chủ giống như 1 agentic độc lập.

Viết lại thành bản mô tả, kiến trúc và chia phase để chạy nhé 