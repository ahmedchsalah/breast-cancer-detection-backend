import client, { setAuthToken } from './client.js';

/**
 * AUTH API
 * Base path: /api/auth
 * Public routes (no token required) + protected logout/me
 */

const auth = {
  /**
   * Get list of organizations for registration dropdown.
   * GET /api/auth/organizations
   * @returns {Promise<Array<{id: number, name: string, type: string}>>}
   */
  getOrganizations() {
    return client.get('/auth/organizations').then((r) => r.data);
  },

  /**
   * Register a new user (doctor or org_manager).
   * POST /api/auth/register
   * @param {{
   *   name: string,
   *   email: string,
   *   password: string,
   *   password_confirmation: string,
   *   role: 'doctor' | 'org_manager',
   *   phone_number?: string,
   *   organization_id?: number,
   *   organization_name?: string,
   *   organization_type?: 'clinic'|'hospital'|'laboratory'|'radiology_center',
   *   organization_address?: string,
   *   latitude?: number,
   *   longitude?: number,
   *   plan_id?: number
   * }} data
   * @returns {Promise<{message: string, user: import('./types.js').User}>}
   */
  register(data) {
    return client.post('/auth/register', data).then((r) => r.data);
  },

  /**
   * Step 1 of login — validates credentials, returns email to proceed with OTP.
   * POST /api/auth/login
   * @param {{ email: string, password: string }} data
   * @returns {Promise<{message: string, email: string, phone_number: string|null}>}
   */
  login(data) {
    return client.post('/auth/login', data).then((r) => r.data);
  },

  /**
   * Send an OTP to the user via email or SMS.
   * POST /api/auth/send-otp
   * @param {{ email: string, method: 'email' | 'sms' }} data
   * @returns {Promise<{message: string, channel: string}>}
   */
  sendOtp(data) {
    return client.post('/auth/send-otp', data).then((r) => r.data);
  },

  /**
   * Verify OTP to complete login. On success, store the token.
   * POST /api/auth/verify-otp
   * @param {{ email: string, otp?: string, firebase_token?: string }} data
   * @returns {Promise<{message: string, user: import('./types.js').User, token?: string}>}
   */
  async verifyOtp(data) {
    const response = await client.post('/auth/verify-otp', data);
    if (response.data?.token) {
      setAuthToken(response.data.token);
    }
    return response.data;
  },

  /**
   * Logout the authenticated user and clear the local token.
   * POST /api/auth/logout  [requires auth]
   * @returns {Promise<{message: string}>}
   */
  async logout() {
    const result = await client.post('/auth/logout').then((r) => r.data);
    setAuthToken(null);
    return result;
  },

  /**
   * Get the currently authenticated user.
   * GET /api/auth/me  [requires auth]
   * @returns {Promise<import('./types.js').User>}
   */
  me() {
    return client.get('/auth/me').then((r) => r.data);
  },
};

export default auth;
