/**
 * Extended mock fixtures for NextCRM-style modules added in M-FE.W17 →
 * dashboard, accounts, contacts, tasks, calendar, documents.
 */

export const MOCK_ACCOUNTS = [
	{ id: 1, name: 'Acme Vietnam',     industry: 'Manufacturing', size: '50-200', country: 'VN', website: 'https://acme.vn',     owner: 'Hà',  annual_revenue: 12000000000, opportunities: 2, status: 'active',  created_at: '2025-11-12T09:00:00Z' },
	{ id: 2, name: 'Globex',           industry: 'Retail',         size: '200-500', country: 'VN', website: 'https://globex.vn',  owner: 'Quân', annual_revenue: 28000000000, opportunities: 1, status: 'active',  created_at: '2025-12-04T14:00:00Z' },
	{ id: 3, name: 'Initech',          industry: 'Software',       size: '10-50',  country: 'VN', website: 'https://initech.vn', owner: 'Hà',  annual_revenue: 4500000000,  opportunities: 1, status: 'active',  created_at: '2026-01-22T10:00:00Z' },
	{ id: 4, name: 'Umbrella Corp',    industry: 'Healthcare',     size: '500+',   country: 'VN', website: 'https://umbrella.vn', owner: 'Linh', annual_revenue: 80000000000, opportunities: 1, status: 'active',  created_at: '2026-02-08T11:30:00Z' },
	{ id: 5, name: 'Stark Industries', industry: 'Energy',         size: '500+',   country: 'VN', website: 'https://stark.vn',   owner: 'Linh', annual_revenue: 56000000000, opportunities: 1, status: 'active',  created_at: '2026-03-15T08:15:00Z' },
	{ id: 6, name: 'Wayne Enterprises', industry: 'Real estate',   size: '200-500', country: 'VN', website: 'https://wayne.vn',  owner: 'Quân', annual_revenue: 42000000000, opportunities: 0, status: 'inactive', created_at: '2026-04-01T16:00:00Z' },
];

export const MOCK_CONTACTS = [
	{ id: 1, first_name: 'Nguyễn Văn', last_name: 'An',    email: 'an.nguyen@acme.vn',    phone: '0901111222', title: 'Giám đốc kinh doanh',   account_id: 1, owner: 'Hà',  tags: [ 'vip', 'decision_maker' ], created_at: '2026-04-09T10:00:00Z' },
	{ id: 2, first_name: 'Trần Thị',   last_name: 'Bích',  email: 'bich.tran@globex.vn',   phone: '0902333444', title: 'Trưởng phòng IT',       account_id: 2, owner: 'Quân', tags: [ 'technical' ],              created_at: '2026-04-11T08:32:00Z' },
	{ id: 3, first_name: 'Lê Hoàng',   last_name: 'Cường', email: 'cuong.le@initech.vn',   phone: '0903555666', title: 'CTO',                    account_id: 3, owner: 'Hà',  tags: [ 'decision_maker' ],         created_at: '2026-04-15T14:11:00Z' },
	{ id: 4, first_name: 'Phạm',       last_name: 'Mai',    email: 'mai.pham@umbrella.vn', phone: '0904777888', title: 'Operations Manager',     account_id: 4, owner: 'Linh', tags: [ 'champion' ],               created_at: '2026-04-18T17:00:00Z' },
	{ id: 5, first_name: 'Hoàng',      last_name: 'Đức',    email: 'duc.hoang@stark.vn',   phone: '0905999000', title: 'Head of Procurement',    account_id: 5, owner: 'Linh', tags: [],                            created_at: '2026-04-22T09:00:00Z' },
	{ id: 6, first_name: 'Vũ',         last_name: 'Thảo',   email: 'thao.vu@wayne.vn',     phone: '0906111222', title: 'CFO',                    account_id: 6, owner: 'Quân', tags: [ 'budget_owner' ],           created_at: '2026-04-28T11:20:00Z' },
	{ id: 7, first_name: 'Đỗ Minh',    last_name: 'Tuấn',   email: 'tuan.do@acme.vn',      phone: '0907222333', title: 'Sales Lead',             account_id: 1, owner: 'Hà',  tags: [],                            created_at: '2026-05-02T13:45:00Z' },
	{ id: 8, first_name: 'Ngô Thị',    last_name: 'Hoa',    email: 'hoa.ngo@globex.vn',    phone: '0908333444', title: 'Project Manager',        account_id: 2, owner: 'Quân', tags: [ 'champion' ],               created_at: '2026-05-05T09:30:00Z' },
];

export const MOCK_TASKS = [
	{ id: 1, title: 'Gọi follow-up Acme proposal',     status: 'open',        priority: 'high',   due_date: '2026-05-13', assignee: 'Hà',   related_to: 'Opp #11',     completed: false },
	{ id: 2, title: 'Gửi báo giá Globex',              status: 'open',        priority: 'high',   due_date: '2026-05-13', assignee: 'Quân', related_to: 'Opp #12',     completed: false },
	{ id: 3, title: 'Chuẩn bị slide demo Initech',     status: 'in_progress', priority: 'medium', due_date: '2026-05-14', assignee: 'Hà',   related_to: 'Opp #13',     completed: false },
	{ id: 4, title: 'Xác nhận thanh toán Umbrella',    status: 'open',        priority: 'medium', due_date: '2026-05-15', assignee: 'Linh', related_to: 'INV-2026-0011', completed: false },
	{ id: 5, title: 'Cập nhật contact info Wayne',     status: 'open',        priority: 'low',    due_date: '2026-05-18', assignee: 'Quân', related_to: 'Account #6',  completed: false },
	{ id: 6, title: 'Review hợp đồng renewal Globex',  status: 'in_progress', priority: 'high',   due_date: '2026-05-20', assignee: 'Hà',   related_to: 'Account #2',  completed: false },
	{ id: 7, title: 'Onboarding Stark POC',            status: 'open',        priority: 'medium', due_date: '2026-05-22', assignee: 'Linh', related_to: 'Opp #15',     completed: false },
	{ id: 8, title: 'Tổng kết tuần',                   status: 'done',        priority: 'low',    due_date: '2026-05-10', assignee: 'me',   related_to: '',             completed: true  },
];

export const MOCK_EVENTS = [
	{ id: 1, title: 'Demo với Acme',           start: '2026-05-13T10:00:00', end: '2026-05-13T11:00:00', type: 'meeting', attendees: [ 'Hà', 'Nguyễn Văn An' ] },
	{ id: 2, title: 'Đứng họp team CRM',       start: '2026-05-13T15:30:00', end: '2026-05-13T16:00:00', type: 'internal', attendees: [ 'Hà', 'Quân', 'Linh' ] },
	{ id: 3, title: 'Demo Globex',             start: '2026-05-14T14:00:00', end: '2026-05-14T15:30:00', type: 'meeting', attendees: [ 'Quân', 'Trần Thị Bích' ] },
	{ id: 4, title: 'Workshop Initech',        start: '2026-05-15T09:00:00', end: '2026-05-15T12:00:00', type: 'workshop', attendees: [ 'Hà', 'Lê Hoàng Cường' ] },
	{ id: 5, title: 'Sprint review',           start: '2026-05-16T10:00:00', end: '2026-05-16T11:00:00', type: 'internal', attendees: [ 'Hà', 'Quân', 'Linh' ] },
	{ id: 6, title: 'Khách Stark site visit',  start: '2026-05-19T09:00:00', end: '2026-05-19T17:00:00', type: 'meeting', attendees: [ 'Linh', 'Hoàng Đức' ] },
	{ id: 7, title: 'Đào tạo nội bộ',          start: '2026-05-20T13:30:00', end: '2026-05-20T16:30:00', type: 'training', attendees: [ 'Hà', 'Quân' ] },
	{ id: 8, title: 'Ký hợp đồng Umbrella renewal', start: '2026-05-22T10:00:00', end: '2026-05-22T11:00:00', type: 'meeting', attendees: [ 'Linh', 'Phạm Mai' ] },
];

export const MOCK_DOCUMENTS = [
	{ id: 1, name: 'Bảng giá Twin CRM 2026.pdf',      type: 'pdf',   size: 2150400, uploaded_by: 'Hà',  uploaded_at: '2026-05-09T11:30:00Z', related_to: 'Acme' },
	{ id: 2, name: 'Hợp đồng mẫu Enterprise.docx',    type: 'docx',  size: 412800,  uploaded_by: 'Quân', uploaded_at: '2026-05-08T14:00:00Z', related_to: 'Globex' },
	{ id: 3, name: 'Kế hoạch triển khai.xlsx',        type: 'xlsx',  size: 88064,   uploaded_by: 'Hà',  uploaded_at: '2026-05-10T09:15:00Z', related_to: 'Initech' },
	{ id: 4, name: 'Brand guideline.pdf',             type: 'pdf',   size: 5242880, uploaded_by: 'Linh', uploaded_at: '2026-04-22T16:00:00Z', related_to: '—' },
	{ id: 5, name: 'Logo client Wayne.png',           type: 'image', size: 256000,  uploaded_by: 'Quân', uploaded_at: '2026-04-28T11:45:00Z', related_to: 'Wayne' },
];

export const DASHBOARD_REVENUE_TREND = [
	{ month: '2025-12', revenue: 245000000 },
	{ month: '2026-01', revenue: 312000000 },
	{ month: '2026-02', revenue: 287000000 },
	{ month: '2026-03', revenue: 398000000 },
	{ month: '2026-04', revenue: 421000000 },
	{ month: '2026-05', revenue: 360000000 },
];
