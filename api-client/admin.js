import client from './client.js';

/**
 * ADMIN API
 * Base path: /api/admin
 * Requires role: admin
 */

const admin = {
  // ─── Insights ───────────────────────────────────────────────────────────────

  insights: {
    /**
     * GET /api/admin/insights/kpis
     * @returns {Promise<{total_users: number, total_organizations: number, total_predictions: number, total_patients: number, active_models: number, completed_fl_rounds: number}>}
     */
    kpis() {
      return client.get('/admin/insights/kpis').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/user-growth
     * @returns {Promise<Array<{month: string, count: number}>>}
     */
    userGrowth() {
      return client.get('/admin/insights/user-growth').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/organization-distribution
     * @returns {Promise<Array<{type: string, count: number}>>}
     */
    organizationDistribution() {
      return client.get('/admin/insights/organization-distribution').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/organization-status-breakdown
     * @returns {Promise<Array<{status: string, count: number}>>}
     */
    organizationStatusBreakdown() {
      return client.get('/admin/insights/organization-status-breakdown').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/predictions-over-time
     * @returns {Promise<Array<{month: string, total: number, completed: number, failed: number}>>}
     */
    predictionsOverTime() {
      return client.get('/admin/insights/predictions-over-time').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/prediction-results
     * @returns {Promise<{total: number, luminal_a: number, non_luminal_a: number}>}
     */
    predictionResults() {
      return client.get('/admin/insights/prediction-results').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/patient-age-distribution
     * @returns {Promise<Array<{range: string, count: number}>>}
     */
    patientAgeDistribution() {
      return client.get('/admin/insights/patient-age-distribution').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/receptor-status
     * @returns {Promise<{er_positive: number, er_negative: number, er_missing: number, pr_positive: number, pr_negative: number, pr_missing: number, her2_positive: number, her2_negative: number}>}
     */
    receptorStatus() {
      return client.get('/admin/insights/receptor-status').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/model-performance
     * @returns {Promise<Array<{round_number: number, global_accuracy: number, status: string, ai_model_id: number, started_at: string, ai_model: {id: number, name: string, version: string}}>>}
     */
    modelPerformance() {
      return client.get('/admin/insights/model-performance').then((r) => r.data);
    },

    /**
     * GET /api/admin/insights/top-organizations
     * @returns {Promise<Array<{organization_id: number, prediction_count: number, organization: {id: number, name: string, type: string}}>>}
     */
    topOrganizations() {
      return client.get('/admin/insights/top-organizations').then((r) => r.data);
    },
  },

  // ─── Organizations ───────────────────────────────────────────────────────────

  organizations: {
    /**
     * GET /api/admin/organizations
     * @param {{ status?: string, type?: string, search?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/admin/organizations', { params }).then((r) => r.data);
    },

    /**
     * GET /api/admin/organizations/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Organization>}
     */
    get(id) {
      return client.get(`/admin/organizations/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/organizations
     * @param {{ name: string, type: string, contact_email?: string, address?: string, latitude?: number, longitude?: number, plan_id?: number }} data
     * @returns {Promise<import('./types.js').Organization>}
     */
    create(data) {
      return client.post('/admin/organizations', data).then((r) => r.data);
    },

    /**
     * PUT /api/admin/organizations/:id
     * @param {number} id
     * @param {Partial<import('./types.js').Organization>} data
     * @returns {Promise<import('./types.js').Organization>}
     */
    update(id, data) {
      return client.put(`/admin/organizations/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/admin/organizations/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/admin/organizations/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/organizations/:id/approve
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    approve(id) {
      return client.post(`/admin/organizations/${id}/approve`).then((r) => r.data);
    },

    /**
     * POST /api/admin/organizations/:id/reject
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    reject(id) {
      return client.post(`/admin/organizations/${id}/reject`).then((r) => r.data);
    },

    /**
     * POST /api/admin/organizations/:id/suspend
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    suspend(id) {
      return client.post(`/admin/organizations/${id}/suspend`).then((r) => r.data);
    },
  },

  // ─── Users ───────────────────────────────────────────────────────────────────

  users: {
    /**
     * GET /api/admin/users
     * @param {{ role?: string, status?: string, search?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/admin/users', { params }).then((r) => r.data);
    },

    /**
     * GET /api/admin/users/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').User>}
     */
    get(id) {
      return client.get(`/admin/users/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/users
     * @param {{ name: string, email: string, password: string, role: string, organization_id?: number }} data
     * @returns {Promise<import('./types.js').User>}
     */
    create(data) {
      return client.post('/admin/users', data).then((r) => r.data);
    },

    /**
     * PUT /api/admin/users/:id
     * @param {number} id
     * @param {{ name?: string, email?: string, password?: string, role?: string, organization_id?: number }} data
     * @returns {Promise<import('./types.js').User>}
     */
    update(id, data) {
      return client.put(`/admin/users/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/admin/users/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/admin/users/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/users/:id/activate
     * @param {number} id
     * @returns {Promise<{message: string, user: import('./types.js').User}>}
     */
    activate(id) {
      return client.post(`/admin/users/${id}/activate`).then((r) => r.data);
    },

    /**
     * POST /api/admin/users/:id/deactivate
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    deactivate(id) {
      return client.post(`/admin/users/${id}/deactivate`).then((r) => r.data);
    },
  },

  // ─── AI Models ───────────────────────────────────────────────────────────────

  aiModels: {
    /**
     * GET /api/admin/ai-models
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/admin/ai-models', { params }).then((r) => r.data);
    },

    /**
     * GET /api/admin/ai-models/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').AiModel>}
     */
    get(id) {
      return client.get(`/admin/ai-models/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/ai-models
     * @param {{ name: string, slug: string, version: string, inference_type: string, description?: string, auc?: number, accuracy?: number, sensitivity?: number, specificity?: number, f1_score?: number, threshold?: number }} data
     * @returns {Promise<import('./types.js').AiModel>}
     */
    create(data) {
      return client.post('/admin/ai-models', data).then((r) => r.data);
    },

    /**
     * PUT /api/admin/ai-models/:id
     * @param {number} id
     * @param {Partial<import('./types.js').AiModel>} data
     * @returns {Promise<import('./types.js').AiModel>}
     */
    update(id, data) {
      return client.put(`/admin/ai-models/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/admin/ai-models/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/admin/ai-models/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/admin/ai-models/:id/activate
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    activate(id) {
      return client.post(`/admin/ai-models/${id}/activate`).then((r) => r.data);
    },

    /**
     * POST /api/admin/ai-models/:id/deactivate
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    deactivate(id) {
      return client.post(`/admin/ai-models/${id}/deactivate`).then((r) => r.data);
    },
  },

  // ─── Audit Logs ──────────────────────────────────────────────────────────────

  auditLogs: {
    /**
     * GET /api/admin/audit-logs
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/admin/audit-logs', { params }).then((r) => r.data);
    },

    /**
     * GET /api/admin/audit-logs/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').AuditLog>}
     */
    get(id) {
      return client.get(`/admin/audit-logs/${id}`).then((r) => r.data);
    },
  },
};

export default admin;
