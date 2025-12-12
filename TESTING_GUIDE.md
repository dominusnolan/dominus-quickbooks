# Testing Guide for JWT Authentication & REST API

This guide provides step-by-step instructions for testing the new JWT authentication and REST API endpoints.

## Prerequisites

1. WordPress site deployed at `https://staging.milaymechanical.com`
2. User account with "engineer" role
3. API testing tool (Postman, Insomnia, or curl)

## Test Cases

### Test 1: Login with Valid Engineer Credentials

**Endpoint:** `POST /wp-json/dq-quickbooks/v1/auth/login`

**Request:**
```bash
curl -X POST https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "engineer1",
    "password": "your-password"
  }'
```

**Expected Response (200 OK):**
```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "user": {
    "id": 123,
    "username": "engineer1",
    "display_name": "John Engineer",
    "email": "john@example.com",
    "role": "engineer"
  },
  "expires_at": "2025-12-18T12:00:00Z"
}
```

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Returns `success: true`
- ✓ Returns valid JWT token
- ✓ Returns user information
- ✓ Returns expiration timestamp

---

### Test 2: Login with Invalid Credentials

**Request:**
```bash
curl -X POST https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "engineer1",
    "password": "wrong-password"
  }'
```

**Expected Response (401 Unauthorized):**
```json
{
  "code": "rest_authentication_failed",
  "message": "Invalid username or password.",
  "data": {
    "status": 401
  }
}
```

**Pass Criteria:**
- ✓ Returns 401 status code
- ✓ Returns error message

---

### Test 3: Login with Non-Engineer User

**Request:**
```bash
curl -X POST https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "subscriber1",
    "password": "correct-password"
  }'
```

**Expected Response (403 Forbidden):**
```json
{
  "code": "rest_forbidden",
  "message": "Only engineers and administrators can access this API.",
  "data": {
    "status": 403
  }
}
```

**Pass Criteria:**
- ✓ Returns 403 status code
- ✓ Returns forbidden error message

---

### Test 4: Get Workorders with Valid JWT Token

**Endpoint:** `GET /wp-json/dq-quickbooks/v1/workorders`

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
```json
{
  "workorders": [
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
  ],
  "total": 10,
  "total_pages": 1,
  "current_page": 1
}
```

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Returns workorders array
- ✓ Returns pagination data
- ✓ Only returns workorders authored by the authenticated engineer

---

### Test 5: Get Workorders without Token

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders
```

**Expected Response (401 Unauthorized):**
```json
{
  "code": "rest_not_logged_in",
  "message": "Authentication required. Please provide a valid JWT token or log in.",
  "data": {
    "status": 401
  }
}
```

**Pass Criteria:**
- ✓ Returns 401 status code
- ✓ Returns authentication required error

---

### Test 6: Get Workorders with Expired Token

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders \
  -H "Authorization: Bearer EXPIRED_JWT_TOKEN_HERE"
```

**Expected Response (401 Unauthorized):**
```json
{
  "code": "dq_jwt_expired",
  "message": "JWT token has expired.",
  "data": {
    "status": 401
  }
}
```

**Pass Criteria:**
- ✓ Returns 401 status code
- ✓ Returns token expired error

---

### Test 7: Validate Token

**Endpoint:** `GET /wp-json/dq-quickbooks/v1/auth/validate`

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/validate \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
```json
{
  "valid": true,
  "user": {
    "id": 123,
    "username": "engineer1",
    "display_name": "John Engineer",
    "email": "john@example.com"
  }
}
```

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Returns `valid: true`
- ✓ Returns user information

---

### Test 8: Refresh Token

**Endpoint:** `POST /wp-json/dq-quickbooks/v1/auth/refresh`

**Request:**
```bash
curl -X POST https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/refresh \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_at": "2025-12-18T12:00:00Z"
}
```

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Returns new JWT token
- ✓ Returns new expiration timestamp

---

### Test 9: CORS Headers from Allowed Origin

**Request from Browser Console (on https://workorder-cpt-manage--dominusnolan.github.app):**
```javascript
fetch('https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/validate', {
  headers: {
    'Authorization': 'Bearer YOUR_JWT_TOKEN_HERE'
  }
})
.then(response => {
  console.log('Status:', response.status);
  console.log('CORS Headers:', response.headers.get('access-control-allow-origin'));
  return response.json();
})
.then(data => console.log('Data:', data))
.catch(error => console.error('Error:', error));
```

**Expected Result:**
- ✓ No CORS errors in browser console
- ✓ Response includes `Access-Control-Allow-Origin` header
- ✓ Response includes `Access-Control-Allow-Credentials: true`
- ✓ API request succeeds

---

### Test 10: CORS Preflight Request

**Request:**
```bash
curl -X OPTIONS https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders \
  -H "Origin: https://workorder-cpt-manage--dominusnolan.github.app" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization" \
  -v
```

**Expected Response:**
- ✓ Returns 200 status code
- ✓ Includes `Access-Control-Allow-Origin` header
- ✓ Includes `Access-Control-Allow-Methods: GET, POST, OPTIONS`
- ✓ Includes `Access-Control-Allow-Headers: Authorization, Content-Type`

---

### Test 11: Get Single Workorder

**Endpoint:** `GET /wp-json/dq-quickbooks/v1/workorders/{id}`

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders/456 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
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

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Returns single workorder data
- ✓ Only accessible if engineer is the author

---

### Test 12: Get Single Workorder (Not Owned)

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders/999 \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (403 Forbidden):**
```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to access this workorder.",
  "data": {
    "status": 403
  }
}
```

**Pass Criteria:**
- ✓ Returns 403 status code if workorder exists but is not owned by the engineer
- ✓ Returns 404 status code if workorder does not exist

---

### Test 13: Filter Workorders by Status

**Request:**
```bash
curl "https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders?status=open" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
```json
{
  "workorders": [
    {
      "id": 456,
      "title": "WO-2025-001",
      "status": "open",
      ...
    }
  ],
  "total": 5,
  "total_pages": 1,
  "current_page": 1
}
```

**Pass Criteria:**
- ✓ Returns only workorders with "open" status
- ✓ Total count reflects filtered results

---

### Test 14: Pagination

**Request:**
```bash
curl "https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders?page=2&per_page=5" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN_HERE"
```

**Expected Response (200 OK):**
```json
{
  "workorders": [...],
  "total": 25,
  "total_pages": 5,
  "current_page": 2
}
```

**Pass Criteria:**
- ✓ Returns second page of results
- ✓ Respects `per_page` parameter (max 5 items)
- ✓ Pagination metadata is correct

---

### Test 15: Application Passwords (Fallback)

**Setup:**
1. Log in to WordPress admin
2. Go to Users → Your Profile
3. Scroll to "Application Passwords"
4. Generate a new application password
5. Copy the generated password (e.g., "xxxx xxxx xxxx xxxx xxxx xxxx")

**Request:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders \
  -u "engineer1:xxxx xxxx xxxx xxxx xxxx xxxx"
```

**Expected Response (200 OK):**
```json
{
  "workorders": [...],
  "total": 10,
  "total_pages": 1,
  "current_page": 1
}
```

**Pass Criteria:**
- ✓ Returns 200 status code
- ✓ Application Password authentication works as fallback
- ✓ Returns workorder data

---

## Test Summary Checklist

- [ ] Test 1: Login with valid engineer credentials
- [ ] Test 2: Login with invalid credentials
- [ ] Test 3: Login with non-engineer user
- [ ] Test 4: Get workorders with valid JWT token
- [ ] Test 5: Get workorders without token
- [ ] Test 6: Get workorders with expired token
- [ ] Test 7: Validate token
- [ ] Test 8: Refresh token
- [ ] Test 9: CORS headers from allowed origin
- [ ] Test 10: CORS preflight request
- [ ] Test 11: Get single workorder
- [ ] Test 12: Get single workorder (not owned)
- [ ] Test 13: Filter workorders by status
- [ ] Test 14: Pagination
- [ ] Test 15: Application Passwords (fallback)

## Troubleshooting

### Issue: "Invalid JWT token signature"

**Possible Causes:**
- WordPress `AUTH_KEY` changed after token generation
- Token was copied incorrectly (contains extra spaces or line breaks)

**Solution:**
- Log in again to get a new token
- Ensure token is copied without modifications

### Issue: "CORS error in browser console"

**Possible Causes:**
- Request origin is not in the allowed list
- Server not sending CORS headers correctly

**Solution:**
- Check that your origin matches one of: `https://workorder-cpt-manage--dominusnolan.github.app`, `http://localhost:5173`, `http://localhost:3000`
- Check browser Network tab for CORS headers in response

### Issue: "No workorders returned"

**Possible Causes:**
- Engineer has no workorders assigned (not the post author)
- Status filter excludes all workorders

**Solution:**
- Verify engineer is the author of some workorders in WordPress admin
- Remove status filter to see all workorders

### Issue: "Token has expired"

**Solution:**
- Tokens expire after 7 days
- Use the `/auth/refresh` endpoint to get a new token
- Or log in again with username/password

## Security Notes

1. Always use HTTPS in production
2. Store tokens securely (not in localStorage for sensitive apps)
3. Implement token refresh before expiration
4. Handle 401 errors by redirecting to login
5. Ensure WordPress `AUTH_KEY` is set to a strong, unique value
