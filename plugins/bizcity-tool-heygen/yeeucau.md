đỡ lặp thao tác clone voice: clone xong lưu voice_id để tái sử dụng nhiều lần. HeyGen có hệ voice management và voice library để dùng lại voice trong các lần generate sau.

rẻ và ổn định hơn: phần “nặng” là khâu thiết lập ban đầu; còn về sau chỉ thay text/script.

dễ đóng gói thành option trong plugin: mỗi “nhân vật AI” có một bộ config riêng.

phù hợp BizCity automation: admin chỉ cấu hình một lần, còn user/agent chỉ cần đẩy script vào là chạy.

Mô tả yêu cầu

Title : Video Avatar by Heygen

Module: Quản lý nhân vật AI và tạo video lipsync hằng ngày bằng HeyGen API
1. Mục tiêu

Xây dựng một module cho phép quản trị viên tạo và quản lý nhiều nhân vật AI khác nhau.
Mỗi nhân vật có thể có:

voice riêng đã clone từ mẫu giọng

avatar riêng hoặc ảnh đại diện cố định

prompt tính cách riêng

style lời thoại riêng

cấu hình mặc định để tái sử dụng nhiều lần

Từ các cấu hình đã lưu sẵn này, hệ thống có thể chạy hằng ngày hoặc theo từng job để:

nhận lời thoại hoặc script

dùng voice_id đã lưu để tạo speech hoặc truyền text trực tiếp

gắn avatar hoặc ảnh đại diện

render video lipsync

poll trạng thái

lấy video_url hoàn tất để trả về hoặc tiếp tục ghép flow automation khác

Mục tiêu của thiết kế này là:

tránh lặp lại bước clone voice

tiết kiệm chi phí

tăng độ ổn định

dễ đóng gói thành plugin

phù hợp với cơ chế BizCity automation, nơi admin cấu hình một lần và agent / workflow chỉ cần đẩy script vào để chạy

2. Phạm vi chức năng

Module gồm 2 lớp chính:

Lớp A. Cấu hình 1 lần

Dùng cho quản trị viên để khởi tạo và lưu các cấu hình nền của từng nhân vật AI.

Bao gồm:

upload voice sample

clone voice qua HeyGen

lấy và lưu voice_id

có thể lưu luôn avatar_id

hoặc lưu image_url cố định

khai báo prompt tính cách cho nhân vật

khai báo phong cách nói

lưu thành hồ sơ nhân vật AI để tái sử dụng nhiều lần

Lớp B. Prompt tạo video

Bằng cách chọn nhân vật, soạn lời thoại , chọn voice_id và nhấn để tạo .
Intent provider sẽ dựa trên logic này để nhận lệnh tạo video avatar heygen.

Dùng cho hệ thống automation, AI agent hoặc người dùng cuối để sinh video mới từ các cấu hình đã có.

Bao gồm:

chọn một nhân vật AI đã cấu hình

nhập lời thoại hoặc script

dùng voice_id đã lưu để tạo speech hoặc truyền text trực tiếp vào HeyGen

gắn avatar / image

tạo job render lipsync

poll trạng thái job

lấy video_url khi hoàn tất

3. Lý do thiết kế theo 2 lớp
3.1. Giảm lặp thao tác

Không phải clone voice ở mỗi lần chạy.
Sau khi clone xong, hệ thống lưu voice_id để tái sử dụng nhiều lần.

3.2. Rẻ hơn và ổn định hơn

Bước clone voice là bước thiết lập ban đầu, có thể tốn thời gian và chi phí hơn.
Sau đó mỗi ngày chỉ cần thay nội dung script để tạo video mới.

3.3. Dễ đóng gói thành plugin

Mỗi nhân vật AI là một cấu hình độc lập, có thể quản lý trong wp-admin hoặc giao diện BizCity.

3.4. Phù hợp với BizCity automation

Admin chỉ cấu hình một lần.
Các automation flow, planner hoặc AI agent chỉ việc chọn nhân vật và đẩy nội dung vào để chạy.

3.5. Hỗ trợ mở rộng quy mô

Có thể tạo nhiều nhân vật AI khác nhau để phục vụ nhiều mục đích:

MC giới thiệu sản phẩm

chuyên gia tư vấn

trợ lý nhắc việc

người dẫn chuyện

nhân vật cảm xúc / chữa lành

nhân vật dạy học cho trẻ em

nhân vật review / bán hàng

4. Yêu cầu chức năng chi tiết
4.1. Quản lý nhân vật AI

Hệ thống phải cho phép tạo nhiều nhân vật AI khác nhau.

Mỗi nhân vật có các thuộc tính cơ bản:

tên nhân vật

mã định danh nội bộ

mô tả ngắn

prompt tính cách

ngôn ngữ mặc định

phong cách giao tiếp

voice sample

voice_id

avatar_id hoặc image_url

trạng thái kích hoạt

người tạo

ngày tạo / ngày cập nhật

Ví dụ:

Nhân vật A: nữ, nhẹ nhàng, chữa lành

Nhân vật B: nam, mạnh mẽ, MC bán hàng

Nhân vật C: giáo viên tiếng Anh cho trẻ em

Nhân vật D: trợ lý BizCity chuyên nhắc việc, nói ngắn gọn

4.2. Upload voice sample

Admin có thể tải lên file voice mẫu cho từng nhân vật.

Yêu cầu:

chấp nhận upload file audio

lưu media hoặc URL file

gắn với hồ sơ nhân vật

cho phép thay mẫu mới nếu cần clone lại

4.3. Clone voice

Từ voice sample đã upload, hệ thống gọi HeyGen API để clone giọng.

Kết quả:

nhận về voice_id

lưu voice_id vào hồ sơ nhân vật

cập nhật trạng thái clone thành công / thất bại

lưu log lỗi nếu có

Yêu cầu:

chỉ clone khi admin chủ động bấm

không clone lại ở mỗi job

cho phép clone lại nếu thay đổi voice sample

4.4. Quản lý avatar

Mỗi nhân vật có thể dùng một trong 2 cách:

Cách 1: dùng avatar_id

Phù hợp khi đã có avatar được tạo / chọn trong HeyGen.

Cách 2: dùng image_url

Phù hợp khi dùng ảnh đại diện cố định hoặc ảnh được sinh từ hệ thống khác.

Yêu cầu:

cho phép chọn một trong hai

ưu tiên avatar_id nếu có

nếu không có avatar_id thì dùng image_url

có thể cấu hình ảnh mặc định cho từng nhân vật

4.5. Prompt tính cách nhân vật

Mỗi nhân vật cần có prompt tính cách riêng để hệ thống tạo lời thoại đúng chất.

Ví dụ các trường:

persona prompt

tone of voice

style ngắn / dài

CTA mặc định

ngữ cảnh sử dụng

Ví dụ:

“Nữ MC nhẹ nhàng, truyền cảm, nói chậm, gần gũi”

“Chuyên gia tư vấn mạnh mẽ, rõ ràng, đáng tin”

“Người bạn AI thân thiện dành cho trẻ em, ngôn ngữ đơn giản”

Prompt này có thể được dùng ở bước sinh script trước khi render video.

4.6. Tạo job video hằng ngày / mỗi lần chạy

Khi chạy job, hệ thống cần:

chọn nhân vật AI

nhập lời thoại hoặc script

hoặc truyền brief để AI tự soạn lời thoại theo persona

lấy voice_id từ cấu hình đã lưu

lấy avatar_id hoặc image_url

gửi job tạo video lipsync lên HeyGen

nhận về video_id hoặc job id

poll trạng thái đến khi hoàn tất

nhận video_url

4.7. Hai chế độ chạy job
Mode 1: Text trực tiếp vào HeyGen

Hệ thống truyền:

voice_id

script text

avatar_id hoặc image_url

HeyGen tự sinh speech và lipsync trong cùng flow.

Phù hợp với:

video ngắn

lời chào

video CTA

video bán hàng nhanh

Mode 2: TTS trước, lipsync sau

Hệ thống thực hiện:

tạo speech từ text bằng voice_id

lưu audio_url

dùng audio đó để render lipsync video

Phù hợp với:

cần kiểm tra audio trước

muốn tái sử dụng audio cho nhiều kênh

muốn ghép thêm B-roll / subtitle / workflow khác

4.8. Poll trạng thái video

Vì render video là bất đồng bộ, hệ thống phải có cơ chế poll.

Yêu cầu:

lưu video_id

kiểm tra trạng thái định kỳ

cập nhật trạng thái: queued / processing / completed / failed

khi completed thì lưu video_url

khi failed thì lưu lỗi để admin kiểm tra

4.9. Quản lý lịch sử job

Mỗi lần tạo video cần lưu log job.

Thông tin cần lưu:

job_id nội bộ

character_id

voice_id đã dùng

avatar_id hoặc image_url đã dùng

script

mode chạy

trạng thái

video_id từ nhà cung cấp

video_url

lỗi nếu có

thời gian tạo

người tạo hoặc workflow nguồn

5. Yêu cầu phi chức năng
5.1. Tối ưu chi phí

Hệ thống phải tránh clone voice lặp lại.
Voice chỉ clone một lần và dùng lâu dài.

5.2. Dễ mở rộng

Có thể thêm nhân vật mới bất cứ lúc nào mà không ảnh hưởng các nhân vật cũ.

5.3. Dễ tích hợp automation

Phải có API hoặc action node để workflow gọi nhanh theo kiểu:

chọn nhân vật

truyền nội dung

tạo video

nhận kết quả

5.4. Dễ quản trị

Admin cần có giao diện đơn giản để:

tạo nhân vật

upload voice

clone voice

test lời thoại

xem danh sách jobs

kiểm tra lỗi render

5.5. Có khả năng dùng hằng ngày

Thiết kế phải hướng tới việc chạy số lượng lớn mỗi ngày, ví dụ:

video chào buổi sáng

video nhắc lịch

video bán hàng hằng ngày

video CTA cho từng chiến dịch

video theo template nhiều nhân vật

6. Luồng xử lý tổng thể
6.1. Luồng cấu hình 1 lần
Tạo nhân vật AI
    ↓
Upload voice sample
    ↓
Clone voice qua HeyGen
    ↓
Nhận voice_id
    ↓
Lưu voice_id vào hồ sơ nhân vật
    ↓
Thiết lập avatar_id hoặc image_url
    ↓
Thiết lập prompt tính cách
    ↓
Lưu hoàn tất
6.2. Luồng chạy mỗi job
Chọn nhân vật AI
    ↓
Nhập script hoặc brief
    ↓
Lấy voice_id đã lưu
    ↓
Lấy avatar_id hoặc image_url
    ↓
Tạo speech hoặc truyền text trực tiếp
    ↓
Tạo job video lipsync
    ↓
Poll trạng thái
    ↓
Nhận video_url
    ↓
Trả kết quả / đẩy sang bước ghép video tiếp theo

9. Yêu cầu tích hợp BizCity Automation

Module cần hỗ trợ dạng action/tool để AI agent hoặc workflow builder có thể gọi như sau:

Action 1: create_character

Tạo hồ sơ nhân vật AI.

Action 2: upload_voice_sample

Gắn file mẫu giọng cho nhân vật.

Action 3: clone_voice

Gọi HeyGen để clone voice và lưu voice_id.

Action 4: update_character_avatar

Cập nhật avatar_id hoặc image_url.

Action 5: generate_script

Dựa trên prompt tính cách để sinh lời thoại.

Action 6: create_lipsync_video

Dùng:

character_id

script

mode

để tạo video.

Action 7: poll_video_status

Kiểm tra trạng thái job và lấy video_url.

10. Định hướng UI quản trị
Màn hình 1: Danh sách nhân vật AI

Hiển thị:

tên nhân vật

voice_id

avatar

trạng thái

ngày cập nhật

nút test nhanh

Màn hình 2: Tạo / sửa nhân vật

Các trường:

tên

mô tả

prompt tính cách

style giọng

upload voice sample

clone voice

voice_id

avatar_id hoặc image_url

ngôn ngữ

trạng thái active

Màn hình 3: Tạo video test

Nhập:

chọn nhân vật

nhập script

chọn mode

bấm render

Màn hình 4: Nhật ký job

Hiển thị:

job id

nhân vật

script rút gọn

trạng thái

video_url

lỗi



