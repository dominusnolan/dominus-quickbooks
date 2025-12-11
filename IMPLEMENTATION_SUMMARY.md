# JWT Authentication & REST API Implementation Summary

## Overview

This implementation adds JWT token-based authentication and enhanced REST API endpoints to allow the Spark web app at `https://workorder-cpt-manage--dominusnolan.github.app/` to fetch workorder data for logged-in engineers.

## Files Created

### 1. `includes/class-dq-jwt-auth.php`
New JWT authentication class that handles:
- Token generation with HMAC-SHA256 signing
- Token validation and decoding
- User retrieval from tokens
- Secure secret key management

**Key Features:**
- Uses WordPress `AUTH_KEY` constant as secret (with validation)
- Auto-generates secure random secret if `AUTH_KEY` is weak
- 7-day token expiration (configurable via `dq_jwt_token_expiration` filter)
- Timing-safe signature comparison
- Extracts tokens from `Authorization: Bearer` headers

### 2. `API_DOCUMENTATION.md`
Comprehensive API documentation including:
- Authentication flow (login, validate, refresh)
- Workorder endpoints (list, single)
- Request/response examples
- JavaScript usage examples
- Troubleshooting guide

### 3. `TESTING_GUIDE.md`
Detailed testing guide with:
- 15 comprehensive test cases
- Expected responses for each scenario
- CORS testing instructions
- Application Passwords testing
- Troubleshooting section

## Files Modified

### 1. `includes/class-dq-workorder-rest-api.php`
Enhanced with JWT authentication support:

**New Endpoints Added:**
- `POST /auth/login` - Authenticate with username/password
- `GET /auth/validate` - Validate current JWT token
- `POST /auth/refresh` - Refresh expiring token

**Changes to Existing Endpoints:**
- Updated `permission_callback` to support JWT tokens
- Added fallback to Application Passwords
- Enhanced CORS support for multiple origins
- Added OPTIONS preflight request handling

**CORS Configuration:**
- Production: `https://workorder-cpt-manage--dominusnolan.github.app`
- Local Dev: `http://localhost:5173`, `http://localhost:3000`
- Filterable via `dq_workorder_api_cors_origins` hook

### 2. `includes/helpers.php`
Added Application Passwords support:
```php
add_filter( 'wp_is_application_passwords_available', '__return_true' );

add_filter( 'wp_is_application_passwords_available_for_user', function( $available, $user ) {
    if ( in_array( 'engineer', (array) $user->roles, true ) ) {
        return true;
    }
    if ( in_array( 'administrator', (array) $user->roles, true ) ) {
        return true;
    }
    return $available;
}, 10, 2 );
```

### 3. `dominus-quickbooks.php`
Added JWT auth class to plugin includes:
```php
require_once DQQB_PATH . 'includes/class-dq-jwt-auth.php';
```

## API Endpoints Summary

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/auth/login` | POST | None | Login, get JWT token |
| `/auth/validate` | GET | JWT | Check if token is valid |
| `/auth/refresh` | POST | JWT | Get new token |
| `/workorders` | GET | JWT | List engineer's workorders |
| `/workorders/{id}` | GET | JWT | Get single workorder |

**Base URL:** `https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1`

## Authentication Flow

1. **Login:**
   ```
   POST /auth/login
   Body: { "username": "engineer1", "password": "password" }
   Response: { "token": "eyJ...", "user": {...}, "expires_at": "..." }
   ```

2. **Use Token:**
   ```
   GET /workorders
   Header: Authorization: Bearer eyJ...
   ```

3. **Refresh Token (before expiration):**
   ```
   POST /auth/refresh
   Header: Authorization: Bearer eyJ...
   Response: { "token": "new_eyJ...", "expires_at": "..." }
   ```

## Security Features

1. **Strong Secret Key Validation**
   - Minimum 32 characters required for `AUTH_KEY`
   - Auto-generates secure random secret if weak
   - Stores generated secret in `wp_options` table

2. **Token Validation**
   - HMAC-SHA256 signature verification
   - Expiration checking
   - Issuer validation
   - Timing-safe comparison to prevent timing attacks

3. **Authorization**
   - Role-based access (engineer or administrator only)
   - Engineers can only access their own workorders (post_author check)
   - Returns 401 for invalid/expired tokens
   - Returns 403 for insufficient permissions

4. **Input Sanitization**
   - All inputs sanitized with WordPress functions
   - Token extracted safely from headers
   - CORS origin validated against whitelist

## Workorder Data Structure

Each workorder in the API response includes:

```json
{
  "id": 456,
  "title": "WO-2025-001",
  "status": "open",
  "date_created": "2025-12-01 10:00:00",
  "date_modified": "2025-12-05 15:30:00",
  "wo_state": "CA",
  "wo_customer_email": "customer@example.com",
  "schedule_date": "2025-12-15 09:00:00",
  "closed_on": "",
  "permalink": "https://staging.milaymechanical.com/workorder/wo-2025-001/"
}
```

**Data Sources:**
- `id`, `title`, `date_created`, `date_modified`, `permalink` - WordPress post data
- `status` - From `status` or `category` taxonomy
- `wo_state`, `wo_customer_email` - Post meta
- `schedule_date`, `closed_on` - ACF fields

## Query Logic

Workorders are filtered to show only those authored by the authenticated engineer:

```php
$args = array(
    'post_type'      => 'workorder',
    'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
    'posts_per_page' => $per_page,
    'paged'          => $page,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'author'         => $user->ID, // Critical: only author's workorders
);
```

## Pagination

- Default: 10 items per page
- Maximum: 100 items per page
- Parameters: `page`, `per_page`

**Response includes:**
```json
{
  "workorders": [...],
  "total": 100,
  "total_pages": 10,
  "current_page": 1
}
```

## Filtering

- Status filter: `?status=open` (uses taxonomy slug)
- Filters are optional
- Empty filters return all workorders

## Error Responses

All errors follow WordPress REST API format:

```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 401
  }
}
```

**Common Error Codes:**
- `rest_authentication_failed` (401) - Invalid credentials
- `rest_not_logged_in` (401) - Missing token
- `dq_jwt_expired` (401) - Token expired
- `dq_jwt_invalid_signature` (401) - Invalid token
- `rest_forbidden` (403) - Insufficient permissions
- `rest_post_not_found` (404) - Workorder not found

## Application Passwords (Fallback)

Engineers can use WordPress Application Passwords as an alternative to JWT:

1. Generate password in WordPress admin (Users → Profile)
2. Use HTTP Basic Authentication
3. Works with all protected endpoints

**Example:**
```bash
curl -u "engineer1:xxxx xxxx xxxx xxxx xxxx xxxx" \
  https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders
```

## CORS Configuration

**Allowed Origins:**
- `https://workorder-cpt-manage--dominusnolan.github.app` (Production Spark app)
- `http://localhost:5173` (Vite local dev)
- `http://localhost:3000` (React/Next.js local dev)

**Headers Sent:**
- `Access-Control-Allow-Origin: <origin>`
- `Access-Control-Allow-Credentials: true`
- `Access-Control-Allow-Methods: GET, POST, OPTIONS`
- `Access-Control-Allow-Headers: Authorization, Content-Type`

**Customization:**
```php
add_filter( 'dq_workorder_api_cors_origins', function( $origins ) {
    $origins[] = 'https://my-custom-origin.com';
    return $origins;
} );
```

## Configuration Hooks

### Token Expiration
```php
add_filter( 'dq_jwt_token_expiration', function( $seconds ) {
    return 14 * DAY_IN_SECONDS; // 14 days instead of 7
} );
```

### CORS Origins
```php
add_filter( 'dq_workorder_api_cors_origins', function( $origins ) {
    $origins[] = 'https://another-app.com';
    return $origins;
} );
```

## Testing

Refer to `TESTING_GUIDE.md` for comprehensive testing instructions covering:
- Authentication (login, validate, refresh)
- Workorder access (list, single, filtering, pagination)
- Security (invalid credentials, expired tokens, unauthorized access)
- CORS (allowed origins, preflight requests)
- Application Passwords fallback

## Deployment Checklist

- [ ] Ensure WordPress `AUTH_KEY` is set to a strong, unique value
- [ ] Enable SSL/HTTPS on staging and production sites
- [ ] Create engineer user accounts with appropriate permissions
- [ ] Test all 15 test cases from `TESTING_GUIDE.md`
- [ ] Verify CORS headers work from Spark app
- [ ] Test token refresh before expiration
- [ ] Verify engineer can only see their own workorders
- [ ] Test Application Passwords as fallback
- [ ] Monitor error logs for authentication issues

## Security Considerations

1. **Secret Key:** Ensure `AUTH_KEY` in `wp-config.php` is strong and unique
2. **HTTPS:** Always use HTTPS in production to protect tokens
3. **Token Storage:** Store tokens securely on client (not in localStorage for sensitive apps)
4. **Token Refresh:** Implement refresh before 7-day expiration
5. **Error Handling:** Handle 401 errors by redirecting to login
6. **Rate Limiting:** Consider implementing rate limiting for login endpoint
7. **Logging:** Monitor failed authentication attempts

## Future Enhancements

Potential improvements for future iterations:
- Token blacklisting for logout functionality
- Rate limiting on authentication endpoints
- More granular permissions (per-workorder access control)
- Webhook support for real-time updates
- Additional filtering options (date range, customer, etc.)
- Bulk operations endpoint
- File attachment support

## Support

For issues or questions:
1. Check `TESTING_GUIDE.md` for troubleshooting steps
2. Review `API_DOCUMENTATION.md` for usage examples
3. Check WordPress error logs for authentication failures
4. Verify CORS configuration if cross-origin issues occur

## Version History

- **v0.3.0** - Initial JWT authentication and REST API implementation
  - JWT token generation and validation
  - Authentication endpoints (login, validate, refresh)
  - Enhanced workorder endpoints
  - CORS support for Spark web app
  - Application Passwords fallback
  - Comprehensive documentation
