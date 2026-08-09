# ASTRO CHECKLIST EXTENDED RUNBOOK

Ngay cap nhat: 2026-07-09
Pham vi: luong "Tai day du du lieu thien van" o UI AstroDataChecklist + luong Ask Brain mode=astro.

## 0) Muc tieu tai lieu
Tai lieu nay tra loi truc tiep 7 cau hoi van hanh/kien truc:
1. Checklist da luu theo user_id + coachee_id chua?
2. Da luu ngay fetch gan nhat chua?
3. Transit tu dong cap nhat theo frequency nao?
4. Western/Vedic du lieu day du cap nhat theo frequency nao?
5. Khi mo profile detail, co auto check stale + auto fetch khong?
6. Lan dau tao ho so bi 7/8 (pending transit), xu ly auto dispatch the nao?
7. Ask Brain co the bat UI "Tai du lieu thien van" neu thieu du lieu khong?

## 1) So do thanh phan lien quan
- Checklist service/table:
  - plugins/bizcity-twin-ai/plugins/bizcoach-pro/includes/astro/class-astro-checklist.php
  - Table: {prefix}bccm_astro_checklist
- REST endpoint checklist + fetch-all:
  - plugins/bizcity-twin-ai/plugins/bizcoach-pro/includes/frontend/class-self-service-rest.php
  - GET /wp-json/bizcity-bizcoach/v1/me/profiles/{id}/astro-checklist
  - POST /wp-json/bizcity-bizcoach/v1/me/profiles/{id}/fetch-all-astro
- Transit sync engine:
  - sync tay: POST /me/profiles/{id}/sync-transit
  - async sau generate chart: bcpro_async_rebuild_transit (single event +5s)
  - cron: plugins/bizcity-twin-ai/plugins/bizcoach-pro/includes/frontend/class-transit-cron.php
- FE checklist UI:
  - plugins/bizcity-twin-ai/plugins/bizcoach-pro/fe/src/components/chart/AstroDataChecklist.tsx
- Profile detail va dashboard:
  - plugins/bizcity-twin-ai/plugins/bizcoach-pro/fe/src/pages/ProfileDetailPage.tsx
  - plugins/bizcity-twin-ai/plugins/bizcoach-pro/fe/src/pages/DashboardPage.tsx
- Ask Brain astro mode:
  - BE gate: plugins/bizcity-twin-ai/core/twinbrain/includes/class-twinbrain-runtime.php
  - FE panel: plugins/bizcity-twin-ai/modules/twinchat/ui/src/components/askbrain/AskBrainPanel.tsx

## 2) Cau hoi 1: Checklist da luu theo user_id + coachee_id chua?

### Hien trang
- Table bccm_astro_checklist luu key theo coachee_id + data_key (UNIQUE).
- Khong co cot user_id trong checklist table.
- Ownership duoc dam bao boi:
  - can_own_profile()
  - resolve_owner_uid()
  - join/lookup user_id trong bccm_coachees

### Ket luan
- Logic quyen truy cap da theo user.
- Storage checklist hien tai la per-coachee, chua la per-user + per-coachee o muc schema.

### Cach check nhanh
SQL audit:
- SELECT c.user_id, acl.coachee_id, acl.data_key, acl.status
  FROM wp_bccm_astro_checklist acl
  LEFT JOIN wp_bccm_coachees c ON c.id = acl.coachee_id
  WHERE acl.coachee_id = :coachee_id
  ORDER BY acl.data_key;

### Gap va de xuat
- Gap: khong co user_id de index/filter truc tiep tren checklist.
- De xuat: them user_id + index (user_id, coachee_id, data_key) de diagnostics va audit nhanh hon.

## 3) Cau hoi 2: Da luu ngay gan nhat tai du lieu chua?

### Hien trang
- Checklist co:
  - last_fetched_at
  - updated_at
- Rule hien tai trong upsert:
  - status != pending -> set last_fetched_at = now
  - status = pending -> last_fetched_at = null

### Ket luan
- Co luu moc fetch gan nhat.
- pending khong co last_fetched_at la dung theo contract hien tai.

### Cach check nhanh
REST audit:
- GET /wp-json/bizcity-bizcoach/v1/me/profiles/{id}/astro-checklist
- Kiem tra tung item:
  - status
  - count_items
  - min_expected
  - last_fetched_at

## 4) Cau hoi 3: Transit tu dong lay du lieu moi theo frequency nao?

### Hien trang (nhieu lop)
1. Async ngay sau generate chart/fetch-all:
- schedule_transit_async(): wp_schedule_single_event(time()+5)
- Hook: bcpro_async_rebuild_transit
- Handler: handle_async_transit() -> do_transit_fetch(today, day)

2. Manual tu UI:
- POST /me/profiles/{id}/sync-transit

3. Cron he thong:
- Daily sync:
  - Hook: bcpro_transit_daily_sync
  - Interval: daily
  - Scope: ngay hom nay cho tat ca coachee co western natal
- Weekly 30-day batch:
  - Hook: bcpro_transit_30day_batch
  - Interval: weekly
  - Chi fetch ngay thieu/stale (>7 ngay)
- Weekly 7-day:
  - Hook: bcpro_transit_weekly_7d
  - Interval: weekly
  - Chi fetch ngay thieu/stale (>6 gio)

4. FE smart fallback:
- useSmartTransitMonth/useSmartTransitDay
- Neu thieu ngay trong cache -> goi /transit ngay do (live), sau do writer do_transit_fetch se upsert snapshot.

### Ket luan
- Transit da co auto update da tang, khong chi phu thuoc 1 cron.
- Diem yeu con lai: async +5s van phu thuoc WP-Cron runner co duoc kick ngay hay khong.

## 5) Cau hoi 4: Du lieu day du western + vedic lay theo frequency nao?

### Hien trang
- Western + Vedic khong co cron periodic refresh rieng.
- Du lieu duoc tao/cap nhat theo event:
  - create_profile() -> auto do_chart_generate_for_coachee(western)
  - update_profile() -> neu birth data doi thi reset + regenerate western
  - generate_chart() -> generate theo chart_type duoc goi
  - fetch-all-astro() -> sequence Western + Wheel + Vedic

### Ket luan
- Western/Vedic hien tai la event-driven (manual/triggered), chua co frequency theo gio/ngay nhu transit.

### Gap va de xuat
- Gap: stale western/vedic co the ton tai lau, trong khi TwinBrain quality gate check freshness.
- De xuat:
  - them cron refresh western/vedic theo freshness SLA (vi du 36h hoac 24h)
  - hoac auto trigger fetch-all 1 lan khi user vao profile detail neu checklist stale.

## 6) Cau hoi 5: Khi mo /profiles/{id} thi check last update va auto fetch the nao?

### Hien trang UI ProfileDetail
- AstroDataChecklist mount -> loadChecklist() (GET astro-checklist).
- Co autoSyncTransit (1 lan/moi profile) neu transit chua done/du count.
- Chua co auto fetch-all cho western/vedic khi stale.

### Ket luan
- Da co auto check checklist + auto sync transit co dieu kien.
- Chua co auto remediations day du cho tat ca key stale/missing.

### De xuat flow auto tren ProfileDetail
1. GET checklist ngay khi mount.
2. Tinh gate:
- neu failed key hoac stale key trong [western_planets, western_houses, western_aspects, western_wheel_chart, vedic_planets, vedic_extended, vedic_navamsa]
  -> auto POST fetch-all-astro 1 lan (debounce theo profileId + session).
3. Sau fetch-all:
- poll checklist moi 3-5s toi da 30s de cho transit pending -> done.
4. Neu van pending:
- goi sync-transit 1 lan fallback.

## 7) Cau hoi 6: Lan dau tao ho so thuong 7/8, transit pending, auto dispatch sao?

### Hien trang
- create_profile() da schedule async transit neu generate chart success.
- fetch-all-astro() da mark transit pending + schedule async transit.

### Van de co the gap
- WP-Cron trigger tre/khong chay ngay -> checklist dung o pending.

### De xuat ky thuat de "chot" 8/8 on first run
Option A (khuyen nghi):
- Sau fetch-all step 4, goi do_transit_fetch(today) inline voi timeout ngan (8-12s).
- Neu success -> mark_done transit ngay.
- Neu timeout/loi -> giu pending va van queue async cron.

Option B:
- Giu async nhu hien tai, them watchdog retry:
  - Neu transit pending > 120s -> schedule them 1 single event retry.

Option C (FE support):
- Sau fetch-all, FE poll checklist + show trang thai "dang tinh transit" + nut "Lam moi transit ngay".

## 8) Cau hoi 7: Ask Brain co the bat dialog UI "Tai du lieu thien van" khi thieu du lieu khong?

### Hien trang
- Ask Brain da co:
  - AstroPrimaryProfileDialog (tao/sua profile chinh chu)
  - Nut sync transit truc tiep
  - Astro CTA/citation token de mo profile/my astro
- TwinBrain runtime da co quality gate + event astro_refetch_dispatched.

### Chua co
- Chua render AstroDataChecklist/fetch-all ngay trong Ask Brain panel.
- Chua co 1 modal "Tai day du du lieu thien van" de user bam 1 nut roi hoi lai.

### De xuat tich hop (khuyen nghi)
1. BE event moi (hoac mo rong event hien co):
- ten goi y: astro_data_action_required
- payload: { coachee_id, failed_keys[], stale_keys[], reason, astro_url }

2. FE AskBrainPanel handler:
- Khi nhan event tren -> mo modal AstroDataDialog.
- Modal reuse API:
  - GET astro-checklist
  - POST fetch-all-astro
  - POST sync-transit

3. UX sau khi xong:
- Banner xanh: "Da cap nhat du lieu thien van. Bam Hoi lai de lay ket qua moi nhat."
- Nut nhanh: "Hoi lai cau vua roi" (tu dong refill prompt cu).

4. Fail-open:
- Neu modal loi API -> cho phep mo My Astro URL fallback.

## 9) Checklist test nghiem thu (manual)

Test A - Storage
- Tao profile moi, verify co row checklist cho 8 key.
- Verify last_fetched_at co gia tri cho cac key done/failed.

Test B - First run 8/8
- Bam "Tao Day Du Du Lieu".
- Ky vong: 7 key western/vedic done/partial ngay.
- Transit: pending -> done trong <=30s (hoac co fallback sync thanh cong).

Test C - Profile detail auto flow
- Mo /astro/#/profiles/{id}.
- Neu checklist stale/missing -> auto trigger fetch-all (neu bat logic moi).
- Verify query cache chart-data/profiles duoc invalidate dung.

Test D - Ask Brain integration
- Hoi Astro voi coachee da resolve nhung checklist stale.
- Ky vong: panel hien modal CTA "Tai day du du lieu".
- Sau khi complete -> hoi lai va nhan ket qua khong degraded.

## 10) Chot pham vi implementation tiep theo
Phase 1 (de ship nhanh):
- Them modal checklist trong AskBrainPanel, reuse endpoint co san.
- Them retry UX/poll cho transit pending.

Phase 2:
- Them user_id vao bccm_astro_checklist + migration.
- Them auto-refresh western/vedic theo freshness SLA.

Phase 3:
- Dong bo quality gate TwinBrain va ProfileDetail auto-remediation ve cung 1 rule freshness.

## 11) Fallback scenarios + public links cho user tu thao tac

Muc nay dung cho truong hop auto-dispatch khong chay hoac chay cham. Muc tieu la cho user
co duong dan ro rang de tu thao tac:
- nhap/sua thong tin ho so coachee
- dispatch lai tai du lieu (fetch-all)
- lam moi transit
- quay lai Ask Brain de hoi lai

### 11.1 Nguyen tac fallback
- POST API dispatch (fetch-all-astro, sync-transit) la route can session + nonce.
- Vi vay "public link" o day la link UI public route (My Astro) de user bam nut thao tac,
  khong phai direct public POST endpoint.
- Neu user da login WordPress, cac link duoi day thao tac duoc ngay.

### 11.2 Link public/deep-link uu tien

Base:
- /astro/
- /astro/?bizcity_iframe=1#/dashboard

Nhap/sua ho so coachee:
- /astro/?bizcity_iframe=1#/subjects

Mo profile detail (co checklist + dispatch buttons):
- /astro/?bizcity_iframe=1#/profiles/{coachee_id}

Neu can bo qua iframe mode:
- /astro/#/dashboard
- /astro/#/subjects
- /astro/#/profiles/{coachee_id}

Link public report/view de verify ket qua sau dispatch:
- /my-natal-chart/?id={coachee_id}&hash={hash}
- /natal-report/?data={base64url_payload}
- transit report public URL (day/week/month/year) lay tu share_urls trong profile payload.

### 11.3 Fallback matrix theo tinh huong

Case A - Khong co profile chinh chu:
- Dau hieu: Ask Brain bao can profile/chua co profile chinh chu.
- Link thao tac: /astro/?bizcity_iframe=1#/subjects
- User action:
  1) Bam "Tao ho so".
  2) Nhap full_name, dob, birth_time, birth_place (neu co).
  3) Danh dau profile chinh chu (is_self).
- Sau do quay lai Ask Brain hoi lai cau cu.

Case B - Co profile nhung thieu birth data (gio/noi sinh):
- Dau hieu: quality gate fail, ket qua degraded hoac CTA can bo sung du lieu.
- Link thao tac: /astro/?bizcity_iframe=1#/profiles/{coachee_id}
- User action:
  1) Sua profile, dien birth_time + birth_place (+ toa do neu co).
  2) Bam "Tao Day Du Du Lieu" de rebuild western/vedic.
  3) Bam "Lam moi transit" neu transit chua done.

Case C - First run bi pending transit qua lau (7/8):
- Dau hieu: checklist transit pending > 1-2 phut.
- Link thao tac: /astro/?bizcity_iframe=1#/profiles/{coachee_id}
- User action:
  1) Bam "Lam moi transit".
  2) Cho 5-30 giay va bam "Tai lai" checklist neu can.
  3) Neu van pending, bam lai "Tao Day Du Du Lieu" 1 lan.

Case D - Checklist stale/failed sau mot thoi gian:
- Dau hieu: quality gate TwinBrain bao fresh khong dat, co failed keys.
- Link thao tac: /astro/?bizcity_iframe=1#/profiles/{coachee_id}
- User action:
  1) Bam "Tao Day Du Du Lieu".
  2) Theo doi console log 8 step trong card "Du Lieu Thien Van".
  3) Neu co loi API, bam "Tai lai" theo error hint trong component.

Case E - Ask Brain khong co coachee_id ro rang:
- Dau hieu: user hoi "xem ngay tot" nhung he thong resolve sai/chua resolve profile.
- Link thao tac: /astro/?bizcity_iframe=1#/subjects
- User action:
  1) Xac nhan profile chinh chu.
  2) Mo dung profile detail va chay "Tao Day Du Du Lieu".
  3) Quay lai Ask Brain, hoi lai kem ten profile neu can.

### 11.4 CTA text de dat trong Ask Brain (de xuat)

Khi runtime emit astro_checklist_quality_failed hoac astro_refetch_dispatched nhung van thieu data,
FE nen hien CTA co link ro rang:

- CTA 1 (primary): "Mo trang du lieu thien van"
  -> /astro/?bizcity_iframe=1#/profiles/{coachee_id}
- CTA 2: "Nhap/sua ho so coachee"
  -> /astro/?bizcity_iframe=1#/subjects
- CTA 3: "Mo My Astro tong quan"
  -> /astro/?bizcity_iframe=1#/dashboard

Copy goi y:
- "Du lieu chiem tinh chua du/fresh. Bam Mo trang du lieu thien van, chon Tao Day Du Du Lieu,
   sau do quay lai va hoi lai cau vua roi."

### 11.5 Duong fallback toi thieu neu khong co coachee_id

Neu khong co coachee_id de deep-link:
- luon fallback ve /astro/?bizcity_iframe=1#/subjects
- user chon profile trong bang
- UI dieu huong tiep sang /astro/?bizcity_iframe=1#/profiles/{id}
- user dispatch bang 2 nut: "Tao Day Du Du Lieu" + "Lam moi transit"

### 11.6 Checklist QA cho fallback links

- Link /astro/?bizcity_iframe=1#/subjects mo duoc voi role user thuong.
- Link /astro/?bizcity_iframe=1#/profiles/{id} mo duoc va render AstroDataChecklist.
- Bam "Tao Day Du Du Lieu" co tao step log 8 buoc.
- Bam "Lam moi transit" cap nhat checklist transit status.
- Quay lai Ask Brain, hoi lai cung prompt -> khong con degrade do thieu astro data.

### 11.7 Full URL production (bizcity.vn) cho CSKH copy-paste

Domain production dang dung:
- https://bizcity.vn

Link tong quan:
- Dashboard My Astro:
  - https://bizcity.vn/astro/?bizcity_iframe=1#/dashboard
- Danh sach ho so (tao/sua profile):
  - https://bizcity.vn/astro/?bizcity_iframe=1#/subjects

Link profile detail (dispatch fetch-all + sync transit):
- Mau link:
  - https://bizcity.vn/astro/?bizcity_iframe=1#/profiles/{coachee_id}
- Vi du:
  - https://bizcity.vn/astro/?bizcity_iframe=1#/profiles/50

Link fallback khong iframe mode (van dung duoc):
- https://bizcity.vn/astro/#/dashboard
- https://bizcity.vn/astro/#/subjects
- https://bizcity.vn/astro/#/profiles/{coachee_id}

Link verify ket qua public report/view:
- Natal chart public:
  - https://bizcity.vn/my-natal-chart/?id={coachee_id}&hash={hash}
- Natal report public:
  - https://bizcity.vn/natal-report/?data={base64url_payload}

Mau tin nhan CSKH #1 (co coachee_id):
- "Anh/chị bấm link sau để mở đúng hồ sơ và tải dữ liệu thiên văn: https://bizcity.vn/astro/?bizcity_iframe=1#/profiles/{coachee_id}. Sau đó bấm 'Tạo Đầy Đủ Dữ Liệu', chờ 5-30 giây, rồi bấm 'Làm mới transit'. Xong quay lại Ask Brain và hỏi lại câu vừa rồi giúp em."

Mau tin nhan CSKH #2 (chua co coachee_id):
- "Anh/chị vào danh sách hồ sơ tại https://bizcity.vn/astro/?bizcity_iframe=1#/subjects để tạo/chọn hồ sơ chính chủ. Sau đó mở hồ sơ chi tiết và bấm 'Tạo Đầy Đủ Dữ Liệu' + 'Làm mới transit', rồi quay lại Ask Brain hỏi lại giúp em."

Mau tin nhan CSKH #3 (chi can mo tong quan nhanh):
- "Anh/chị mở My Astro tại https://bizcity.vn/astro/?bizcity_iframe=1#/dashboard để kiểm tra trạng thái dữ liệu thiên văn. Nếu thấy transit chưa đủ, vào hồ sơ chi tiết và bấm cập nhật lại."
