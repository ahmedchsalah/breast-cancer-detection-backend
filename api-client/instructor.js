import client from './client.js';

/**
 * INSTRUCTOR / FEDERATED LEARNING API
 * Base path: /api/fl
 * Requires role: instructor or admin
 */

const instructor = {
  // ─── Insights ───────────────────────────────────────────────────────────────

  insights: {
    /**
     * GET /api/fl/insights/kpis
     * @returns {Promise<{total_fl_rounds: number, completed_fl_rounds: number, active_ai_models: number, total_ai_models: number, latest_global_accuracy: number|null, latest_round_number: number|null, total_predictions_served: number}>}
     */
    kpis() {
      return client.get('/fl/insights/kpis').then((r) => r.data);
    },

    /**
     * GET /api/fl/insights/accuracy-over-rounds
     * @returns {Promise<Array<{id: number, ai_model_id: number, round_number: number, global_accuracy: number, started_at: string, ended_at: string, ai_model: {id: number, name: string, version: string}}>>}
     */
    accuracyOverRounds() {
      return client.get('/fl/insights/accuracy-over-rounds').then((r) => r.data);
    },

    /**
     * GET /api/fl/insights/contributions-per-round
     * @returns {Promise<Array<{round_number: number, contribution_count: number}>>}
     */
    contributionsPerRound() {
      return client.get('/fl/insights/contributions-per-round').then((r) => r.data);
    },
  },

  // ─── AI Models ───────────────────────────────────────────────────────────────

  models: {
    /**
     * GET /api/fl/models
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/fl/models', { params }).then((r) => r.data);
    },

    /**
     * GET /api/fl/models/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').AiModel>}
     */
    get(id) {
      return client.get(`/fl/models/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/fl/models
     * @param {{ name: string, slug: string, version: string, inference_type: string, description?: string, auc?: number, accuracy?: number, sensitivity?: number, specificity?: number, f1_score?: number, threshold?: number }} data
     * @returns {Promise<import('./types.js').AiModel>}
     */
    create(data) {
      return client.post('/fl/models', data).then((r) => r.data);
    },

    /**
     * PUT /api/fl/models/:id
     * @param {number} id
     * @param {Partial<import('./types.js').AiModel>} data
     * @returns {Promise<import('./types.js').AiModel>}
     */
    update(id, data) {
      return client.put(`/fl/models/${id}`, data).then((r) => r.data);
    },
  },

  // ─── FL Rounds ───────────────────────────────────────────────────────────────

  rounds: {
    /**
     * GET /api/fl/rounds
     * @param {{ page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/fl/rounds', { params }).then((r) => r.data);
    },

    /**
     * GET /api/fl/rounds/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').FlRound & {contributions: import('./types.js').FlContribution[]}>}
     */
    get(id) {
      return client.get(`/fl/rounds/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/fl/rounds
     * Opens a new FL round for a given model.
     * @param {{ ai_model_id: number }} data
     * @returns {Promise<import('./types.js').FlRound>}
     */
    create(data) {
      return client.post('/fl/rounds', data).then((r) => r.data);
    },

    /**
     * POST /api/fl/rounds/:id/complete
     * Marks the round as completed with the aggregated global accuracy.
     * @param {number} id
     * @param {{ global_accuracy: number }} data  - float between 0 and 1
     * @returns {Promise<{message: string, round: import('./types.js').FlRound}>}
     */
    complete(id, data) {
      return client.post(`/fl/rounds/${id}/complete`, data).then((r) => r.data);
    },
  },

  // ─── Contributions ───────────────────────────────────────────────────────────

  contributions: {
    /**
     * GET /api/fl/contributions?fl_round_id=:id
     * @param {number} flRoundId
     * @returns {Promise<Array<import('./types.js').FlContribution>>}
     */
    listByRound(flRoundId) {
      return client.get('/fl/contributions', { params: { fl_round_id: flRoundId } }).then((r) => r.data);
    },

    /**
     * POST /api/fl/contributions
     * @param {{
     *   fl_round_id: number,
     *   organization_id: number,
     *   local_sample_size: number,
     *   local_accuracy_before: number,
     *   local_accuracy_after: number,
     *   weights_update_path: string
     * }} data
     * @returns {Promise<import('./types.js').FlContribution>}
     */
    create(data) {
      return client.post('/fl/contributions', data).then((r) => r.data);
    },
  },
};

export default instructor;
