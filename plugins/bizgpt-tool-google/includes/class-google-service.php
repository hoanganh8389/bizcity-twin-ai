<?php
/**
 * Google API service wrapper — Gmail, Calendar, Drive, Contacts.
 *
 * All methods require (blog_id, user_id) to resolve the access token.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Google_Service {

    /* ── Base helpers ──────────────────────────────────────── */

    /**
     * Make an authenticated GET request to Google API.
     */
    private static function api_get( $url, $blog_id, $user_id, $params = [] ) {
        $token_data = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        if ( ! $token_data ) {
            return new WP_Error( 'no_token', 'Google chưa kết nối. Vui lòng kết nối Google trước.' );
        }

        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'Accept'        => 'application/json',
            ],
        ] );

        return self::parse_response( $response );
    }

    /**
     * Make an authenticated POST request to Google API.
     */
    private static function api_post( $url, $blog_id, $user_id, $body = [], $content_type = 'application/json' ) {
        return self::api_request( 'POST', $url, $blog_id, $user_id, $body, $content_type );
    }

    /**
     * Generic authenticated HTTP request (POST, PATCH, PUT, DELETE).
     */
    private static function api_request( $method, $url, $blog_id, $user_id, $body = [], $content_type = 'application/json' ) {
        $token_data = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        if ( ! $token_data ) {
            return new WP_Error( 'no_token', 'Google chưa kết nối. Vui lòng kết nối Google trước.' );
        }

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'Content-Type'  => $content_type,
                'Accept'        => 'application/json',
            ],
        ];

        if ( $body && $content_type === 'application/json' ) {
            $args['body'] = wp_json_encode( $body );
        } elseif ( $body ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $url, $args );
        return self::parse_response( $response );
    }

    /**
     * Parse a Google API response.
     */
    private static function parse_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = $body['error']['message'] ?? ( $body['error_description'] ?? "HTTP {$code}" );
            return new WP_Error( 'google_api_error', $msg, [ 'status' => $code ] );
        }

        return $body;
    }

    /* ══════════════════════════════════════════════════════════
     *  GMAIL
     * ══════════════════════════════════════════════════════════ */

    /**
     * List Gmail messages (threads) with snippet preview.
     */
    public static function gmail_list( $blog_id, $user_id, $args = [] ) {
        $max_results = absint( $args['max_results'] ?? 10 );
        $query       = sanitize_text_field( $args['query'] ?? '' );

        $params = [
            'maxResults' => min( $max_results, 50 ),
        ];
        if ( $query ) {
            $params['q'] = $query;
        }

        $result = self::api_get( 'https://gmail.googleapis.com/gmail/v1/users/me/messages', $blog_id, $user_id, $params );
        if ( is_wp_error( $result ) ) return $result;

        $messages = [];
        $msg_ids  = $result['messages'] ?? [];

        // Fetch details for each message (batch up to max_results)
        foreach ( array_slice( $msg_ids, 0, $max_results ) as $msg ) {
            $detail = self::api_get(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $msg['id'],
                $blog_id, $user_id,
                [ 'format' => 'metadata', 'metadataHeaders' => 'From,To,Subject,Date' ]
            );
            if ( is_wp_error( $detail ) ) continue;

            $headers = [];
            foreach ( $detail['payload']['headers'] ?? [] as $h ) {
                $headers[ $h['name'] ] = $h['value'];
            }

            $messages[] = [
                'id'      => $msg['id'],
                'from'    => $headers['From'] ?? '',
                'to'      => $headers['To'] ?? '',
                'subject' => $headers['Subject'] ?? '(no subject)',
                'date'    => $headers['Date'] ?? '',
                'snippet' => $detail['snippet'] ?? '',
            ];
        }

        return [
            'total_estimate' => $result['resultSizeEstimate'] ?? 0,
            'messages'       => $messages,
        ];
    }

    /**
     * Get full email content by message ID.
     */
    public static function gmail_get( $blog_id, $user_id, $message_id ) {
        $msg_id = sanitize_text_field( $message_id );
        return self::api_get(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $msg_id,
            $blog_id, $user_id,
            [ 'format' => 'full' ]
        );
    }

    /**
     * Send an email via Gmail.
     */
    public static function gmail_send( $blog_id, $user_id, $args ) {
        $to      = sanitize_email( $args['to'] );
        $subject = sanitize_text_field( $args['subject'] );
        $body    = wp_kses_post( $args['body'] );

        if ( ! is_email( $to ) ) {
            return new WP_Error( 'invalid_email', 'Địa chỉ email không hợp lệ.' );
        }

        // Build RFC 2822 message
        $token_data  = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        $from_email  = $token_data ? $token_data['google_email'] : '';

        $raw_message  = "From: {$from_email}\r\n";
        $raw_message .= "To: {$to}\r\n";
        $raw_message .= "Subject: =?UTF-8?B?" . base64_encode( $subject ) . "?=\r\n";
        $raw_message .= "MIME-Version: 1.0\r\n";
        $raw_message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $raw_message .= "\r\n";
        $raw_message .= $body;

        // URL-safe base64
        $encoded = rtrim( strtr( base64_encode( $raw_message ), '+/', '-_' ), '=' );

        return self::api_post(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            $blog_id, $user_id,
            [ 'raw' => $encoded ]
        );
    }

    /* ══════════════════════════════════════════════════════════
     *  CALENDAR
     * ══════════════════════════════════════════════════════════ */

    /**
     * List calendar events.
     */
    public static function calendar_list( $blog_id, $user_id, $args = [] ) {
        $params = [
            'maxResults'  => min( absint( $args['max_results'] ?? 10 ), 50 ),
            'singleEvents' => 'true',
            'orderBy'     => 'startTime',
        ];

        if ( ! empty( $args['time_min'] ) ) {
            $params['timeMin'] = self::to_rfc3339( $args['time_min'] );
        } else {
            $params['timeMin'] = gmdate( 'c' );
        }

        if ( ! empty( $args['time_max'] ) ) {
            $params['timeMax'] = self::to_rfc3339( $args['time_max'] );
        }

        $result = self::api_get(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            $blog_id, $user_id, $params
        );
        if ( is_wp_error( $result ) ) return $result;

        $events = [];
        foreach ( $result['items'] ?? [] as $item ) {
            $events[] = [
                'id'          => $item['id'],
                'title'       => $item['summary'] ?? '(no title)',
                'start'       => $item['start']['dateTime'] ?? $item['start']['date'] ?? '',
                'end'         => $item['end']['dateTime'] ?? $item['end']['date'] ?? '',
                'location'    => $item['location'] ?? '',
                'description' => $item['description'] ?? '',
                'html_link'   => $item['htmlLink'] ?? '',
            ];
        }

        return [ 'events' => $events ];
    }

    /**
     * Create a calendar event.
     */
    public static function calendar_create( $blog_id, $user_id, $args ) {
        $event = [
            'summary' => sanitize_text_field( $args['title'] ),
            'start'   => [ 'dateTime' => self::to_rfc3339( $args['start_time'] ), 'timeZone' => 'Asia/Ho_Chi_Minh' ],
            'end'     => [ 'dateTime' => self::to_rfc3339( $args['end_time'] ),   'timeZone' => 'Asia/Ho_Chi_Minh' ],
        ];

        if ( ! empty( $args['description'] ) ) {
            $event['description'] = sanitize_text_field( $args['description'] );
        }

        if ( ! empty( $args['attendees'] ) ) {
            $emails = array_map( 'trim', explode( ',', sanitize_text_field( $args['attendees'] ) ) );
            $event['attendees'] = [];
            foreach ( $emails as $email ) {
                if ( is_email( $email ) ) {
                    $event['attendees'][] = [ 'email' => $email ];
                }
            }
        }

        return self::api_post(
            'https://www.googleapis.com/calendar/v3/calendars/primary/events',
            $blog_id, $user_id,
            $event
        );
    }

    /* ══════════════════════════════════════════════════════════
     *  DRIVE
     * ══════════════════════════════════════════════════════════ */

    /**
     * List files in Google Drive.
     */
    public static function drive_list( $blog_id, $user_id, $args = [] ) {
        $params = [
            'pageSize' => min( absint( $args['max_results'] ?? 10 ), 50 ),
            'fields'   => 'files(id,name,mimeType,size,modifiedTime,webViewLink)',
            'orderBy'  => 'modifiedTime desc',
        ];

        if ( ! empty( $args['query'] ) ) {
            $q = sanitize_text_field( $args['query'] );
            $params['q'] = "name contains '{$q}'";
        }

        $result = self::api_get(
            'https://www.googleapis.com/drive/v3/files',
            $blog_id, $user_id, $params
        );
        if ( is_wp_error( $result ) ) return $result;

        $files = [];
        foreach ( $result['files'] ?? [] as $f ) {
            $files[] = [
                'id'            => $f['id'],
                'name'          => $f['name'],
                'mime_type'     => $f['mimeType'],
                'size'          => $f['size'] ?? '',
                'modified_time' => $f['modifiedTime'] ?? '',
                'web_link'      => $f['webViewLink'] ?? '',
            ];
        }

        return [ 'files' => $files ];
    }

    /* ══════════════════════════════════════════════════════════
     *  CONTACTS
     * ══════════════════════════════════════════════════════════ */

    /**
     * List Google Contacts.
     */
    public static function contacts_list( $blog_id, $user_id, $args = [] ) {
        $max = min( absint( $args['max_results'] ?? 20 ), 100 );

        $params = [
            'pageSize'    => $max,
            'personFields' => 'names,emailAddresses,phoneNumbers,organizations',
        ];

        $url = 'https://people.googleapis.com/v1/people/me/connections';

        // Use search if query provided
        if ( ! empty( $args['query'] ) ) {
            $url = 'https://people.googleapis.com/v1/people:searchContacts';
            $params = [
                'query'      => sanitize_text_field( $args['query'] ),
                'readMask'   => 'names,emailAddresses,phoneNumbers,organizations',
                'pageSize'   => $max,
            ];
        }

        $result = self::api_get( $url, $blog_id, $user_id, $params );
        if ( is_wp_error( $result ) ) return $result;

        $contacts = [];
        $items    = $result['connections'] ?? $result['results'] ?? [];
        foreach ( $items as $person ) {
            $p = $person['person'] ?? $person;
            $contacts[] = [
                'name'    => $p['names'][0]['displayName'] ?? '',
                'email'   => $p['emailAddresses'][0]['value'] ?? '',
                'phone'   => $p['phoneNumbers'][0]['value'] ?? '',
                'company' => $p['organizations'][0]['name'] ?? '',
            ];
        }

        return [ 'contacts' => $contacts ];
    }

    /* ══════════════════════════════════════════════════════════
     *  GOOGLE DOCS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create a new Google Doc (blank or with title).
     *
     * @return array|WP_Error  { documentId, title, revisionId }
     */
    public static function docs_create( $blog_id, $user_id, $args = [] ) {
        $body = [ 'title' => sanitize_text_field( $args['title'] ?? 'Untitled' ) ];
        return self::api_post(
            'https://docs.googleapis.com/v1/documents',
            $blog_id, $user_id, $body
        );
    }

    /**
     * Read a Google Doc (full structured content).
     *
     * @return array|WP_Error  Document resource
     */
    public static function docs_get( $blog_id, $user_id, $document_id ) {
        return self::api_get(
            'https://docs.googleapis.com/v1/documents/' . urlencode( $document_id ),
            $blog_id, $user_id
        );
    }

    /**
     * Batch update a Google Doc (insert, replace, delete text, etc.).
     *
     * @param array $requests  Array of batchUpdate request objects
     * @return array|WP_Error
     */
    public static function docs_batch_update( $blog_id, $user_id, $document_id, $requests ) {
        return self::api_post(
            'https://docs.googleapis.com/v1/documents/' . urlencode( $document_id ) . ':batchUpdate',
            $blog_id, $user_id,
            [ 'requests' => $requests ]
        );
    }

    /* ══════════════════════════════════════════════════════════
     *  GOOGLE SHEETS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create a new Google Spreadsheet.
     *
     * @return array|WP_Error  { spreadsheetId, spreadsheetUrl, sheets[] }
     */
    public static function sheets_create( $blog_id, $user_id, $args = [] ) {
        $body = [
            'properties' => [ 'title' => sanitize_text_field( $args['title'] ?? 'Untitled' ) ],
        ];
        if ( ! empty( $args['sheet_titles'] ) && is_array( $args['sheet_titles'] ) ) {
            $body['sheets'] = [];
            foreach ( $args['sheet_titles'] as $i => $st ) {
                $body['sheets'][] = [
                    'properties' => [
                        'sheetId' => $i,
                        'title'   => sanitize_text_field( $st ),
                    ],
                ];
            }
        }
        return self::api_post(
            'https://sheets.googleapis.com/v4/spreadsheets',
            $blog_id, $user_id, $body
        );
    }

    /**
     * Get spreadsheet metadata (sheets list, properties).
     */
    public static function sheets_get( $blog_id, $user_id, $spreadsheet_id ) {
        return self::api_get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode( $spreadsheet_id ),
            $blog_id, $user_id,
            [ 'fields' => 'spreadsheetId,spreadsheetUrl,properties.title,sheets.properties' ]
        );
    }

    /**
     * Read cell values from a range (e.g. "Sheet1!A1:D10").
     *
     * @return array|WP_Error  { range, values[][] }
     */
    public static function sheets_read( $blog_id, $user_id, $spreadsheet_id, $range ) {
        return self::api_get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode( $spreadsheet_id )
                . '/values/' . urlencode( $range ),
            $blog_id, $user_id
        );
    }

    /**
     * Append rows to a sheet.
     *
     * @param array $values  2D array of row values
     * @return array|WP_Error
     */
    public static function sheets_append( $blog_id, $user_id, $spreadsheet_id, $range, $values ) {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode( $spreadsheet_id )
             . '/values/' . urlencode( $range ) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';
        return self::api_post( $url, $blog_id, $user_id, [
            'range'          => $range,
            'majorDimension' => 'ROWS',
            'values'         => $values,
        ] );
    }

    /**
     * Update cells in a range (overwrite).
     *
     * @param array $values  2D array of row values
     * @return array|WP_Error
     */
    public static function sheets_update( $blog_id, $user_id, $spreadsheet_id, $range, $values ) {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode( $spreadsheet_id )
             . '/values/' . urlencode( $range ) . '?valueInputOption=USER_ENTERED';
        return self::api_request( 'PUT', $url, $blog_id, $user_id, [
            'range'          => $range,
            'majorDimension' => 'ROWS',
            'values'         => $values,
        ] );
    }

    /**
     * Batch update a spreadsheet (add/delete sheets, formatting, etc.).
     */
    public static function sheets_batch_update( $blog_id, $user_id, $spreadsheet_id, $requests ) {
        return self::api_post(
            'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode( $spreadsheet_id ) . ':batchUpdate',
            $blog_id, $user_id,
            [ 'requests' => $requests ]
        );
    }

    /* ══════════════════════════════════════════════════════════
     *  GOOGLE SLIDES
     * ══════════════════════════════════════════════════════════ */

    /**
     * Create a new Google Slides presentation.
     *
     * @return array|WP_Error  { presentationId, title, slides[] }
     */
    public static function slides_create( $blog_id, $user_id, $args = [] ) {
        return self::api_post(
            'https://slides.googleapis.com/v1/presentations',
            $blog_id, $user_id,
            [ 'title' => sanitize_text_field( $args['title'] ?? 'Untitled' ) ]
        );
    }

    /**
     * Get presentation metadata.
     */
    public static function slides_get( $blog_id, $user_id, $presentation_id ) {
        return self::api_get(
            'https://slides.googleapis.com/v1/presentations/' . urlencode( $presentation_id ),
            $blog_id, $user_id
        );
    }

    /**
     * Batch update a presentation (add slides, insert text/images/shapes, etc.).
     */
    public static function slides_batch_update( $blog_id, $user_id, $presentation_id, $requests ) {
        return self::api_post(
            'https://slides.googleapis.com/v1/presentations/' . urlencode( $presentation_id ) . ':batchUpdate',
            $blog_id, $user_id,
            [ 'requests' => $requests ]
        );
    }

    /* ══════════════════════════════════════════════════════════
     *  GOOGLE DRIVE — Extended
     * ══════════════════════════════════════════════════════════ */

    /**
     * Get file metadata.
     */
    public static function drive_get( $blog_id, $user_id, $file_id ) {
        return self::api_get(
            'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_id ),
            $blog_id, $user_id,
            [ 'fields' => 'id,name,mimeType,size,modifiedTime,webViewLink,webContentLink,parents' ]
        );
    }

    /**
     * Delete a file from Drive.
     */
    public static function drive_delete( $blog_id, $user_id, $file_id ) {
        return self::api_request(
            'DELETE',
            'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_id ),
            $blog_id, $user_id
        );
    }

    /**
     * Export a Google Workspace file (Doc/Sheet/Slide) to a given MIME type.
     *
     * @param string $mime_type  e.g. application/pdf, text/plain, text/csv
     * @return string|WP_Error   Raw file content
     */
    public static function drive_export( $blog_id, $user_id, $file_id, $mime_type = 'application/pdf' ) {
        $token_data = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        if ( ! $token_data ) {
            return new WP_Error( 'no_token', 'Google chưa kết nối.' );
        }

        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_id )
             . '/export?' . http_build_query( [ 'mimeType' => $mime_type ] );

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [ 'Authorization' => 'Bearer ' . $token_data['access_token'] ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return new WP_Error( 'export_failed', "Export failed: HTTP {$code}" );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Download a non-Workspace file (binary) from Drive.
     *
     * @return string|WP_Error  Raw binary content
     */
    public static function drive_download( $blog_id, $user_id, $file_id ) {
        $token_data = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        if ( ! $token_data ) {
            return new WP_Error( 'no_token', 'Google chưa kết nối.' );
        }

        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode( $file_id ) . '?alt=media';

        $response = wp_remote_get( $url, [
            'timeout' => 60,
            'headers' => [ 'Authorization' => 'Bearer ' . $token_data['access_token'] ],
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return new WP_Error( 'download_failed', "Download failed: HTTP {$code}" );
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Search Drive with a full query string (Drive API v3 q parameter).
     */
    public static function drive_search( $blog_id, $user_id, $query, $max_results = 20 ) {
        return self::api_get(
            'https://www.googleapis.com/drive/v3/files',
            $blog_id, $user_id,
            [
                'q'        => $query,
                'pageSize' => min( absint( $max_results ), 100 ),
                'fields'   => 'files(id,name,mimeType,size,modifiedTime,webViewLink)',
                'orderBy'  => 'modifiedTime desc',
            ]
        );
    }

    /* ── Helpers ───────────────────────────────────────────── */

    /**
     * Convert various date formats to RFC 3339.
     */
    private static function to_rfc3339( $date_string ) {
        $ts = strtotime( $date_string );
        if ( ! $ts ) $ts = time();
        return gmdate( 'Y-m-d\TH:i:s+07:00', $ts );
    }
}
