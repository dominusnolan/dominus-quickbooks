# Workorder REST API Documentation

This document describes the REST API endpoints for the Spark web app to access workorder data.

## Base URL

```
https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1
```

## Authentication

The API supports two authentication methods:

1. **JWT Token Authentication** (Recommended)
2. **WordPress Application Passwords** (Fallback)

### JWT Authentication

#### Login

**Endpoint:** `POST /auth/login`

**Request Body:**
```json
{
  "username": "engineer1",
  "password": "your-password"
}
```

**Success Response (200 OK):**
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

**Error Responses:**
- `401 Unauthorized`: Invalid credentials
- `403 Forbidden`: User does not have engineer or administrator role

#### Validate Token

**Endpoint:** `GET /auth/validate`

**Headers:**
```
Authorization: Bearer <token>
```

**Success Response (200 OK):**
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

**Error Responses:**
- `401 Unauthorized`: Invalid or expired token

#### Refresh Token

**Endpoint:** `POST /auth/refresh`

**Headers:**
```
Authorization: Bearer <token>
```

**Success Response (200 OK):**
```json
{
  "success": true,
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "expires_at": "2025-12-18T12:00:00Z"
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or expired token

### Using JWT Token

Include the token in the `Authorization` header for all protected endpoints:

```
Authorization: Bearer <token>
```

## Workorder Endpoints

All workorder endpoints require authentication (JWT or Application Password).

### List Workorders

**Endpoint:** `GET /workorders`

**Headers:**
```
Authorization: Bearer <token>
```

**Query Parameters:**
- `page` (integer, optional): Page number (default: 1)
- `per_page` (integer, optional): Items per page (default: 10, max: 100)
- `status` (string, optional): Filter by status slug. Valid values: "open", "scheduled", "closed", "uncategorized"

**Success Response (200 OK):**
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
  "total": 42,
  "total_pages": 5,
  "current_page": 1
}
```

**Error Responses:**
- `401 Unauthorized`: Missing or invalid token
- `403 Forbidden`: User does not have permission

### Get Single Workorder

**Endpoint:** `GET /workorders/{id}`

**Headers:**
```
Authorization: Bearer <token>
```

**Success Response (200 OK):**
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

**Error Responses:**
- `401 Unauthorized`: Missing or invalid token
- `403 Forbidden`: User does not have permission to access this workorder
- `404 Not Found`: Workorder not found

**Note:** Engineers can only access their own workorders (workorders where they are the author).

## CORS Support

The API supports Cross-Origin Resource Sharing (CORS) for the following origins:

- `https://workorder-cpt-manage--dominusnolan.github.app` (Production Spark app)
- `http://localhost:5173` (Local development - Vite)
- `http://localhost:3000` (Local development - React/Next.js)

Additional origins can be added using the `dq_workorder_api_cors_origins` filter.

## Application Passwords (Fallback)

If JWT authentication is not available, you can use WordPress Application Passwords:

1. Log in to WordPress admin as an engineer or administrator
2. Go to Users → Profile
3. Scroll to "Application Passwords" section
4. Generate a new application password
5. Use HTTP Basic Authentication with your username and the application password

**Example:**
```bash
curl -u "engineer1:xxxx xxxx xxxx xxxx xxxx xxxx" \
  https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders
```

## Token Expiration

JWT tokens expire after 7 days by default. The expiration time can be customized using the `dq_jwt_token_expiration` filter.

## Example Usage (JavaScript)

### Login and Store Token

```javascript
async function login(username, password) {
  const response = await fetch(
    'https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/login',
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ username, password }),
    }
  );

  if (!response.ok) {
    throw new Error('Login failed');
  }

  const data = await response.json();
  // Store token in localStorage or secure storage
  localStorage.setItem('jwt_token', data.token);
  return data;
}
```

### Fetch Workorders

```javascript
async function fetchWorkorders(page = 1, perPage = 10) {
  const token = localStorage.getItem('jwt_token');
  
  const response = await fetch(
    `https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders?page=${page}&per_page=${perPage}`,
    {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error('Failed to fetch workorders');
  }

  return await response.json();
}
```

### Refresh Token

```javascript
async function refreshToken() {
  const token = localStorage.getItem('jwt_token');
  
  const response = await fetch(
    'https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/refresh',
    {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
    }
  );

  if (!response.ok) {
    throw new Error('Token refresh failed');
  }

  const data = await response.json();
  localStorage.setItem('jwt_token', data.token);
  return data;
}
```

## Security Considerations

1. **HTTPS Only**: Always use HTTPS in production to protect tokens in transit
2. **Token Storage**: Store tokens securely (e.g., httpOnly cookies or secure storage)
3. **Token Rotation**: Implement token refresh before expiration
4. **Error Handling**: Handle 401 errors by redirecting to login
5. **Secret Key**: Ensure WordPress `AUTH_KEY` is set to a strong, unique value in `wp-config.php`

## Testing the API

### Using cURL

**Login:**
```bash
curl -X POST https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"engineer1","password":"your-password"}'
```

**List Workorders:**
```bash
curl https://staging.milaymechanical.com/wp-json/dq-quickbooks/v1/workorders \
  -H "Authorization: Bearer <your-token>"
```

### Using Postman

1. Create a new POST request to `/auth/login`
2. Set body to JSON with username and password
3. Copy the token from the response
4. Create a new GET request to `/workorders`
5. Add Authorization header: `Bearer <token>`
6. Send the request

## Troubleshooting

### 401 Unauthorized Errors

- Verify the token is being sent in the Authorization header
- Check that the token hasn't expired (7 days default)
- Ensure the token format is correct: `Bearer <token>`

### 403 Forbidden Errors

- Verify the user has the "engineer" or "administrator" role
- Check that the engineer is accessing their own workorders

### CORS Errors

- Verify the origin is in the allowed list
- Check browser console for specific CORS error messages
- Ensure the server is sending correct CORS headers

### Empty Response

- Verify the engineer has workorders assigned to them (as post author)
- Check the status filter if applied
- Verify the workorder custom post type exists
