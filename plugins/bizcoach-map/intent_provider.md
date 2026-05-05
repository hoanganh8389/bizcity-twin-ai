1 plugin sẽ có nguyên tắc giải quyết từng bước.

1. Profile view => tạo form input nhận prompt hoặc ảnh qua vision model và sử dụng knowledge hoặc thuật toán của riêng plugin để trả kết quả.
2. Dựa trên kết quả tinh chỉnh từ profile view, sẽ cung cấp intent provider để assistant nhận dạng tool, đưa waiting fiels vào dể execute.

Về việc improve tối ưu logic 2 của preintent để xác định tool cần thiết, kích hoạt quá trình HIL chính xác , chúng ta làm như sau:
1. Plugin trong intent provider khi khai báo sẽ cầnchia thành 2 loại tool. 1 dạng input chính, main tool, luôn ưu tiên xuất hiện, có mặt khi AI nhận diện để đẩy vào. 1 dạng là các tool phụ xuất hiện ở input prompt text, chỗ / tìm danh sách công cụ, các công cụ sẽ hiện ra khi user nhấn / hoặc user chọn tool trong danh sách hiện tại.
2. user sau khi nhấn vào và thì tool sẽ xuất hiện trong input dưới dạng  pill trong input dưới ký hiệu /tao_ban_do_sao chẳng hạ. Mỗi input mỗi lần chỉ chứa 1 tool , nên nếu chọn tool khác thì tool trong input phải bị thay thế.
3. Khi user nhắn, pill này cũng được gửi theo trong message của user, để intent engine nhận diện và kích hoạt cơ chế HIL, như vậ , hỏi , thu thập dữ liệu và tạo bản đồ một cách tường minh, chính xác. rõ ràng mà ko cần phải predicted.

Như vậy chúng ta cần phải làm như sau:

1. Hãy làm lại intent provider của bizcoach, để có thể dễ dàng nhận prompt liên quan đến bản đồ, xem hôm nay ngày mai, là đẩy thẳng vào bizcoach y như đẩy vào plugin gemini. hiện nay tarot và calo đã điều chỉnh intent provider theo hướng như vậy.
2. Hãy update lại UI của admin chat dashboard, để chọn tool theo hướng này và vẫn giữ để lưu tool_name vào bizcity_webchat_messages.
3. Tại bước 2, sau khi đã pass qua logic 1 hoặc logic 2 để bước vào intent engine xử lý thì trước đó, với tin nhắn từ lấy trong bizcity_webchat_messages gồm: plugin_slug , tool_name, và nội dung của tin nhắn có thể dạng ảnh hoặc dạng media url thì cần phải assistats xác định được tool_name là gì, các waiting fields là gì sau khi phân tích parse text và ảnh trong tin nhắn ra còn lại gồm những gì thì đẩy qua LLM provider để trả lời cho user là đã có thông tin gì, còn thiếu cái gì. Hoặc đủ rồi thì cũng trả lời lại rằng bạn muốn dùng tool_name này để xử lý nội dung này ...
Đủ và thiếu đều cần phải trả lời để confirm. 
4. Kể từ khi xác định tool_name và plugin_slug, cần phải keep để duy trì việc lưu giá trị 2 cột này trong suốt quá trình HIL cho đến khi completed hoặc cancel thì mới dừng lại ko lưu.  Như vậy thì việc gọi messages theo intent_conversation_id mới chính xác , đủ ngữ cảnh để fill dữ liệu đủ.


Hãy update việc này vào kiến trúc và roadmap.md Tiếp tục xây dựng UI UX cho pre-intent ok.