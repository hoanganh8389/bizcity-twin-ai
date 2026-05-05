<?php
/**
 * Phase 0.16 / Vòng 4 — Sprint 9
 * Intent_Pre_Rules deterministic test fixture.
 *
 * Two checks per row:
 *   • try_match  → returns one of {'help', 'cancel', null} (we only need
 *                  to know whether the row short-circuits and which rule
 *                  fired). null means "fall through to runner" — that's
 *                  also a correct behaviour for ack/approval/rejection
 *                  by design (HIL handled inside runner).
 *   • intent_kind → expected output of detect_intent_kind() — one of
 *                  {creative, task, chat, null}. Use null when the rule
 *                  is supposed to be ambiguous.
 *
 * NO LLM calls. Runs in milliseconds.
 *
 * Target: 100% pass — these are pure regex. A failure means the regex
 * was changed without updating the fixture (or vice-versa).
 *
 * @since 4.0.0
 */

return [
	/* ── help ─────────────────────────────────────────────────────────── */
	[ 'msg' => '/help',                          'try_match' => 'help',   'intent_kind' => null     ],
	[ 'msg' => 'help',                           'try_match' => 'help',   'intent_kind' => null     ],
	[ 'msg' => 'trợ giúp',                       'try_match' => 'help',   'intent_kind' => null     ],
	[ 'msg' => 'hướng dẫn',                      'try_match' => 'help',   'intent_kind' => null     ],

	/* ── cancel (no session_adapter → null per current Sprint 1 wiring) */
	[ 'msg' => '/cancel',                        'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'hủy',                            'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'dừng lại',                       'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'stop',                           'try_match' => null,     'intent_kind' => 'chat'   ],

	/* ── ack / approval (pass-through, runner HIL handles) ─────────────── */
	[ 'msg' => 'ok',                             'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'đồng ý',                         'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'chạy đi',                        'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'tiếp tục',                       'try_match' => null,     'intent_kind' => 'chat'   ],

	/* ── rejection ────────────────────────────────────────────────────── */
	[ 'msg' => 'không',                          'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'thôi',                           'try_match' => null,     'intent_kind' => 'chat'   ],

	/* ── retry ────────────────────────────────────────────────────────── */
	[ 'msg' => 'thử lại',                        'try_match' => null,     'intent_kind' => 'chat'   ],
	[ 'msg' => 'làm lại',                        'try_match' => null,     'intent_kind' => 'chat'   ],

	/* ── creative ─────────────────────────────────────────────────────── */
	[ 'msg' => 'Viết bài về AI',                                    'try_match' => null, 'intent_kind' => 'creative' ],
	[ 'msg' => 'Soạn content cho fanpage',                          'try_match' => null, 'intent_kind' => 'creative' ],
	[ 'msg' => 'Draft một blog post về startup',                    'try_match' => null, 'intent_kind' => 'creative' ],

	/* ── task ─────────────────────────────────────────────────────────── */
	[ 'msg' => 'Đăng bài này lên Facebook',                         'try_match' => null, 'intent_kind' => 'task'     ],
	[ 'msg' => 'Gửi email cho khách hàng VIP',                      'try_match' => null, 'intent_kind' => 'task'     ],
	[ 'msg' => 'Tạo zalo group cho đội kinh doanh',                 'try_match' => null, 'intent_kind' => 'task'     ],

	/* ── chat (Q&A / chitchat) ────────────────────────────────────────── */
	[ 'msg' => 'AI là gì?',                                          'try_match' => null, 'intent_kind' => 'chat'     ],
	[ 'msg' => 'Tại sao trời mưa?',                                 'try_match' => null, 'intent_kind' => 'chat'     ],
	[ 'msg' => 'cảm ơn bạn',                                        'try_match' => null, 'intent_kind' => 'chat'     ],
	[ 'msg' => 'hi',                                                 'try_match' => null, 'intent_kind' => 'chat'     ],
];
