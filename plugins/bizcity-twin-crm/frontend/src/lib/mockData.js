/**
 * Mock fixtures for the FE-first sprint. Backend (M-CRM.M1/M2/M3) will replace
 * these with RTK Query endpoints once schema lands.
 */

const VND = 'VND';
const USD = 'USD';

export const MOCK_LEADS = [
	{ id: 1, name: 'Nguyễn Văn An',  email: 'an.nguyen@acme.vn',  phone: '0901111222', source: 'facebook', status: 'new',         owner: 'Hà',  score: 72, created_at: '2026-05-09T10:00:00Z' },
	{ id: 2, name: 'Trần Thị Bích',  email: 'bich.tran@globex.vn', phone: '0902333444', source: 'web',     status: 'qualified',   owner: 'Quân', score: 88, created_at: '2026-05-08T08:32:00Z' },
	{ id: 3, name: 'Lê Hoàng Cường', email: 'cuong.le@initech.vn', phone: '0903555666', source: 'zalo',    status: 'contacted',   owner: 'Hà',  score: 56, created_at: '2026-05-07T14:11:00Z' },
	{ id: 4, name: 'Phạm Mai',       email: 'mai.pham@umbrella.vn', phone: '0904777888', source: 'referral', status: 'unqualified', owner: 'Linh', score: 30, created_at: '2026-05-06T17:00:00Z' },
];

export const SALES_STAGES = [
	{ id: 'prospecting', label: 'Prospecting', color: '#94a3b8' },
	{ id: 'qualification', label: 'Qualification', color: '#0ea5e9' },
	{ id: 'proposal', label: 'Proposal', color: '#f59e0b' },
	{ id: 'negotiation', label: 'Negotiation', color: '#8b5cf6' },
	{ id: 'closed_won', label: 'Closed Won', color: '#22c55e' },
	{ id: 'closed_lost', label: 'Closed Lost', color: '#ef4444' },
];

export const MOCK_OPPORTUNITIES = [
	{ id: 11, title: 'Acme — Twin CRM Pro 5 seats',   account: 'Acme Vietnam',    stage: 'qualification', amount: 45000000, currency: VND, probability: 60, owner: 'Hà',  expected_close_date: '2026-06-15' },
	{ id: 12, title: 'Globex — Twin CRM Enterprise',  account: 'Globex',          stage: 'proposal',       amount: 180000000, currency: VND, probability: 50, owner: 'Quân', expected_close_date: '2026-07-01' },
	{ id: 13, title: 'Initech — Add-on Macros pack',  account: 'Initech',         stage: 'negotiation',    amount: 12000000, currency: VND, probability: 75, owner: 'Hà',  expected_close_date: '2026-05-30' },
	{ id: 14, title: 'Umbrella — Yearly renewal',     account: 'Umbrella Corp',   stage: 'closed_won',     amount: 36000000, currency: VND, probability: 100, owner: 'Linh', expected_close_date: '2026-05-10' },
	{ id: 15, title: 'Stark — POC chatbot',           account: 'Stark Industries', stage: 'prospecting',   amount: 25000000, currency: VND, probability: 20, owner: 'Linh', expected_close_date: '2026-08-12' },
	{ id: 16, title: 'Wayne — Migration consulting',  account: 'Wayne Enterprises', stage: 'closed_lost',  amount: 8000000,  currency: VND, probability: 0,   owner: 'Quân', expected_close_date: '2026-04-22' },
];

export const MOCK_CONTRACTS = [
	{ id: 21, number: 'CNT-2026-001', account: 'Umbrella Corp',     opportunity_id: 14, status: 'active',   start_date: '2026-05-10', end_date: '2027-05-10', renewal_date: '2027-04-10', total_amount: 36000000, currency: VND },
	{ id: 22, number: 'CNT-2026-002', account: 'Acme Vietnam',      opportunity_id: 11, status: 'draft',    start_date: '2026-06-15', end_date: '2027-06-15', renewal_date: '2027-05-15', total_amount: 45000000, currency: VND },
	{ id: 23, number: 'CNT-2025-040', account: 'Globex',            opportunity_id: null, status: 'expired', start_date: '2025-03-01', end_date: '2026-03-01', renewal_date: '2026-02-01', total_amount: 24000000, currency: VND },
];

export const MOCK_INVOICES = [
	{ id: 101, number: 'INV-2026-0011', type: 'invoice', account: 'Umbrella Corp',  status: 'paid',           issue_date: '2026-05-10', due_date: '2026-05-25', currency: VND, subtotal: 32727272, total_tax: 3272728, total: 36000000, balance_due: 0,         opportunity_id: 14 },
	{ id: 102, number: 'INV-2026-0012', type: 'invoice', account: 'Initech',        status: 'partially_paid', issue_date: '2026-05-08', due_date: '2026-05-22', currency: VND, subtotal: 10909091, total_tax: 1090909, total: 12000000, balance_due: 6000000,   opportunity_id: 13 },
	{ id: 103, number: 'INV-2026-0013', type: 'invoice', account: 'Globex',         status: 'issued',         issue_date: '2026-05-11', due_date: '2026-05-26', currency: VND, subtotal: 163636364, total_tax: 16363636, total: 180000000, balance_due: 180000000, opportunity_id: 12 },
	{ id: 104, number: 'INV-2026-0014', type: 'proforma', account: 'Acme Vietnam',  status: 'draft',          issue_date: '2026-05-11', due_date: '2026-05-26', currency: VND, subtotal: 40909091, total_tax: 4090909, total: 45000000, balance_due: 45000000, opportunity_id: 11 },
];

export const MOCK_INVOICE_LINES = {
	101: [
		{ id: 1, position: 1, description: 'Twin CRM Yearly Subscription', qty: 1, unit_price: 32727272, discount_percent: 0, tax_rate: 10, subtotal: 32727272, vat: 3272728, total: 36000000 },
	],
	102: [
		{ id: 2, position: 1, description: 'Macros add-on pack',       qty: 1, unit_price: 7272727, discount_percent: 0, tax_rate: 10, subtotal: 7272727, vat: 727273, total: 8000000 },
		{ id: 3, position: 2, description: 'Onboarding (per session)', qty: 4, unit_price: 909091,  discount_percent: 0, tax_rate: 10, subtotal: 3636364, vat: 363636, total: 4000000 },
	],
	103: [
		{ id: 4, position: 1, description: 'Twin CRM Enterprise (10 seats)', qty: 10, unit_price: 14545454, discount_percent: 0, tax_rate: 10, subtotal: 145454545, vat: 14545455, total: 160000000 },
		{ id: 5, position: 2, description: 'Premium support (yearly)',       qty: 1,  unit_price: 18181818, discount_percent: 0, tax_rate: 10, subtotal: 18181818, vat: 1818182, total: 20000000 },
	],
	104: [
		{ id: 6, position: 1, description: 'Twin CRM Pro (5 seats)', qty: 5, unit_price: 8181818, discount_percent: 0, tax_rate: 10, subtotal: 40909091, vat: 4090909, total: 45000000 },
	],
};

export const MOCK_EMAIL_FOLDERS = [
	{ id: 'inbox',   label: 'Inbox',   count: 12 },
	{ id: 'sent',    label: 'Sent',    count: 5 },
	{ id: 'drafts',  label: 'Drafts',  count: 1 },
	{ id: 'spam',    label: 'Spam',    count: 0 },
	{ id: 'trash',   label: 'Trash',   count: 3 },
];

export const MOCK_EMAILS = [
	{ id: 'm1', folder: 'inbox', from: 'an.nguyen@acme.vn',  subject: 'RE: Báo giá Twin CRM 5 seats', preview: 'Xin chào, mình đã review báo giá và thấy ổn nhưng…', received_at: '2026-05-12T08:42:00Z', read: false, has_attachments: true,  contact_id: 1 },
	{ id: 'm2', folder: 'inbox', from: 'support@globex.vn',   subject: 'Yêu cầu demo cho team kỹ thuật', preview: 'Bên mình muốn schedule một buổi demo 30 phút…', received_at: '2026-05-12T07:10:00Z', read: false, has_attachments: false, contact_id: 2 },
	{ id: 'm3', folder: 'inbox', from: 'cuong.le@initech.vn', subject: 'Hợp đồng năm 2026',           preview: 'Mình đã ký hợp đồng và scan gửi lại bạn…',  received_at: '2026-05-11T19:25:00Z', read: true,  has_attachments: true,  contact_id: 3 },
	{ id: 'm4', folder: 'inbox', from: 'newsletter@vendor.com', subject: 'Weekly product update',     preview: 'New features released this week…',           received_at: '2026-05-11T09:00:00Z', read: true,  has_attachments: false, contact_id: null },
	{ id: 'm5', folder: 'inbox', from: 'mai.pham@umbrella.vn', subject: 'Cảm ơn bạn về buổi training', preview: 'Buổi training rất bổ ích, team mình cảm ơn…', received_at: '2026-05-10T15:30:00Z', read: true,  has_attachments: false, contact_id: 4 },
];

export const MOCK_AUDIT = [
	{ id: 1, action: 'created', entity_type: 'contact', entity_id: 1, user: 'admin', created_at: '2026-05-09T10:00:00Z', changes: { name: { old: null, new: 'Nguyễn Văn An' }, email: { old: null, new: 'an.nguyen@acme.vn' } } },
	{ id: 2, action: 'updated', entity_type: 'contact', entity_id: 1, user: 'Hà',    created_at: '2026-05-10T11:42:00Z', changes: { phone: { old: '0901111111', new: '0901111222' } } },
	{ id: 3, action: 'updated', entity_type: 'contact', entity_id: 1, user: 'Quân',  created_at: '2026-05-11T14:05:00Z', changes: { status: { old: 'new', new: 'qualified' }, score: { old: 50, new: 72 } } },
	{ id: 4, action: 'restored', entity_type: 'contact', entity_id: 1, user: 'admin', created_at: '2026-05-12T08:00:00Z', changes: {} },
];

export const MOCK_ACTIVITIES = [
	{ id: 1, type: 'note',    title: 'Khách hàng quan tâm gói Pro',          body: 'Anh An hỏi về so sánh giữa gói Pro và Enterprise.', user: 'Hà',  created_at: '2026-05-12T08:42:00Z' },
	{ id: 2, type: 'call',    title: 'Gọi điện 15 phút',                     body: 'Trao đổi về timeline triển khai. Cam kết gửi proposal trong tuần.', user: 'Quân', created_at: '2026-05-11T16:10:00Z' },
	{ id: 3, type: 'meeting', title: 'Demo qua Zoom',                        body: 'Demo tính năng Macros + Automation. Khách quan tâm SLA.', user: 'Hà',  created_at: '2026-05-10T10:00:00Z' },
	{ id: 4, type: 'email',   title: 'Gửi báo giá v2',                       body: 'Đã gửi báo giá điều chỉnh kèm hợp đồng mẫu.',        user: 'Linh', created_at: '2026-05-09T14:30:00Z' },
	{ id: 5, type: 'task',    title: 'Theo dõi follow-up sau 3 ngày',        body: 'Reminder set 2026-05-15.',                            user: 'Quân', created_at: '2026-05-09T09:00:00Z' },
];

export { VND, USD };
