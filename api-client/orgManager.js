import client from './client.js';

/**
 * ORG MANAGER API
 * Base path: /api/org
 * Requires role: org_manager
 */

const orgManager = {
  // ─── Dashboard ───────────────────────────────────────────────────────────────

  /**
   * GET /api/org/dashboard
   * Combined summary card for the org manager dashboard.
   * @returns {Promise<{organization: import('./types.js').Organization, total_doctors: number, active_doctors: number, total_patients: number, total_predictions: number, subscription: import('./types.js').Subscription|null, plan: import('./types.js').Plan|null}>}
   */
  getDashboard() {
    return client.get('/org/dashboard').then((r) => r.data);
  },

  // ─── Insights ───────────────────────────────────────────────────────────────

  insights: {
    /**
     * GET /api/org/insights/kpis
     * @returns {Promise<{total_doctors: number, active_doctors: number, total_patients: number, total_predictions: number, completed_predictions: number, pending_predictions: number}>}
     */
    kpis() {
      return client.get('/org/insights/kpis').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/patient-growth
     * @returns {Promise<Array<{month: string, count: number}>>}
     */
    patientGrowth() {
      return client.get('/org/insights/patient-growth').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/predictions-over-time
     * @returns {Promise<Array<{month: string, total: number, completed: number, failed: number}>>}
     */
    predictionsOverTime() {
      return client.get('/org/insights/predictions-over-time').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/prediction-results
     * @returns {Promise<{total: number, luminal_a: number, non_luminal_a: number}>}
     */
    predictionResults() {
      return client.get('/org/insights/prediction-results').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/patient-age-distribution
     * @returns {Promise<Array<{range: string, count: number}>>}
     */
    patientAgeDistribution() {
      return client.get('/org/insights/patient-age-distribution').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/receptor-status
     * @returns {Promise<{er_positive: number, er_negative: number, er_missing: number, pr_positive: number, pr_negative: number, pr_missing: number, her2_positive: number, her2_negative: number}>}
     */
    receptorStatus() {
      return client.get('/org/insights/receptor-status').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/doctor-leaderboard
     * @returns {Promise<Array<{doctor_id: number, prediction_count: number, doctor: {id: number, name: string}}>>}
     */
    doctorLeaderboard() {
      return client.get('/org/insights/doctor-leaderboard').then((r) => r.data);
    },

    /**
     * GET /api/org/insights/model-performance
     * @returns {Promise<Array<{round_number: number, global_accuracy: number, ai_model: {id: number, name: string}}>>}
     */
    modelPerformance() {
      return client.get('/org/insights/model-performance').then((r) => r.data);
    },
  },

  // ─── Members ─────────────────────────────────────────────────────────────────

  members: {
    /**
     * GET /api/org/members
     * @param {{ search?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/org/members', { params }).then((r) => r.data);
    },

    /**
     * GET /api/org/members/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').User>}
     */
    get(id) {
      return client.get(`/org/members/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/org/members/:id/approve
     * Activates a pending doctor account.
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    approve(id) {
      return client.post(`/org/members/${id}/approve`).then((r) => r.data);
    },

    /**
     * POST /api/org/members/:id/deactivate
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    deactivate(id) {
      return client.post(`/org/members/${id}/deactivate`).then((r) => r.data);
    },

    /**
     * DELETE /api/org/members/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/org/members/${id}`).then((r) => r.data);
    },
  },

  // ─── Patients (read-only) ────────────────────────────────────────────────────

  patients: {
    /**
     * GET /api/org/patients
     * @param {{ search?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/org/patients', { params }).then((r) => r.data);
    },

    /**
     * GET /api/org/patients/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Patient>}
     */
    get(id) {
      return client.get(`/org/patients/${id}`).then((r) => r.data);
    },
  },

  // ─── Reports (read-only) ─────────────────────────────────────────────────────

  reports: {
    /**
     * GET /api/org/reports
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/org/reports', { params }).then((r) => r.data);
    },

    /**
     * GET /api/org/reports/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Report>}
     */
    get(id) {
      return client.get(`/org/reports/${id}`).then((r) => r.data);
    },
  },

  // ─── Plans & Payments ────────────────────────────────────────────────────────

  payments: {
    /**
     * GET /api/org/plans
     * @returns {Promise<Array<import('./types.js').Plan>>}
     */
    getPlans() {
      return client.get('/org/plans').then((r) => r.data);
    },

    /**
     * POST /api/org/subscribe
     * Initiate a Chargily payment checkout. Returns a checkout_url to redirect the user.
     * @param {{ plan_id: number, duration_months: number }} data
     * @returns {Promise<{message: string, checkout_url: string, payment: import('./types.js').Payment}>}
     */
    subscribe(data) {
      return client.post('/org/subscribe', data).then((r) => r.data);
    },

    /**
     * GET /api/org/payments
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    getHistory(params = {}) {
      return client.get('/org/payments', { params }).then((r) => r.data);
    },

    /**
     * GET /api/org/subscription
     * @returns {Promise<{subscription: import('./types.js').Subscription|null, plan: import('./types.js').Plan|null}>}
     */
    getCurrentSubscription() {
      return client.get('/org/subscription').then((r) => r.data);
    },

    /**
     * GET /api/org/subscription-status
     * @returns {Promise<{status: string, ends_at: string|null, days_remaining: number|null}>}
     */
    getStatus() {
      return client.get('/org/subscription-status').then((r) => r.data);
    },
  },
};

export default orgManager;
