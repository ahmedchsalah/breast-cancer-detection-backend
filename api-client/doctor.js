import client from './client.js';

/**
 * DOCTOR API
 * Base path: /api/doctor
 * Requires role: doctor
 */

const doctor = {
  // ─── Insights ───────────────────────────────────────────────────────────────

  insights: {
    /**
     * GET /api/doctor/insights/kpis
     * @returns {Promise<{total_patients: number, total_examinations: number, total_predictions: number, completed_predictions: number, pending_predictions: number, total_reports: number}>}
     */
    kpis() {
      return client.get('/doctor/insights/kpis').then((r) => r.data);
    },

    /**
     * GET /api/doctor/insights/examinations-over-time
     * @returns {Promise<Array<{month: string, count: number}>>}
     */
    examinationsOverTime() {
      return client.get('/doctor/insights/examinations-over-time').then((r) => r.data);
    },

    /**
     * GET /api/doctor/insights/prediction-results
     * @returns {Promise<{total: number, luminal_a: number, non_luminal_a: number}>}
     */
    predictionResults() {
      return client.get('/doctor/insights/prediction-results').then((r) => r.data);
    },

    /**
     * GET /api/doctor/insights/average-confidence
     * @returns {Promise<{avg_confidence_lum_a: number, avg_confidence_non_lum_a: number}>}
     */
    averageConfidence() {
      return client.get('/doctor/insights/average-confidence').then((r) => r.data);
    },

    /**
     * GET /api/doctor/insights/patient-age-distribution
     * @returns {Promise<Array<{range: string, count: number}>>}
     */
    patientAgeDistribution() {
      return client.get('/doctor/insights/patient-age-distribution').then((r) => r.data);
    },

    /**
     * GET /api/doctor/insights/recent-activity
     * Last 10 examinations with patient and prediction summary.
     * @returns {Promise<Array<{id: number, status: string, examined_at: string, patient: {id: number, patient_identifier: string}, prediction: {id: number, is_lum_a: boolean|null, status: string}|null}>>}
     */
    recentActivity() {
      return client.get('/doctor/insights/recent-activity').then((r) => r.data);
    },
  },

  // ─── Patients ────────────────────────────────────────────────────────────────

  patients: {
    /**
     * GET /api/doctor/patients
     * @param {{ search?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/doctor/patients', { params }).then((r) => r.data);
    },

    /**
     * GET /api/doctor/patients/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Patient>}
     */
    get(id) {
      return client.get(`/doctor/patients/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/patients
     * @param {{
     *   patient_identifier: string,
     *   er_status: boolean,
     *   pr_status: boolean,
     *   her2_binary: boolean,
     *   age: number,
     *   stage_num: 1|2|3|4,
     *   er_status_missing?: boolean,
     *   pr_status_missing?: boolean,
     *   fraction_genome_altered?: number,
     *   buffa_hypoxia_score?: number,
     *   ragnum_hypoxia_score?: number,
     *   winter_hypoxia_score?: number
     * }} data
     * @returns {Promise<import('./types.js').Patient>}
     */
    create(data) {
      return client.post('/doctor/patients', data).then((r) => r.data);
    },

    /**
     * PUT /api/doctor/patients/:id
     * @param {number} id
     * @param {Partial<import('./types.js').Patient>} data
     * @returns {Promise<import('./types.js').Patient>}
     */
    update(id, data) {
      return client.put(`/doctor/patients/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/doctor/patients/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/doctor/patients/${id}`).then((r) => r.data);
    },
  },

  // ─── Examinations ────────────────────────────────────────────────────────────

  examinations: {
    /**
     * GET /api/doctor/examinations
     * @param {{ patient_id?: number, status?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/doctor/examinations', { params }).then((r) => r.data);
    },

    /**
     * GET /api/doctor/examinations/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Examination>}
     */
    get(id) {
      return client.get(`/doctor/examinations/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/examinations
     * @param {{ patient_id: number, chief_complaint?: string, clinical_notes?: string, examined_at?: string }} data
     * @returns {Promise<import('./types.js').Examination>}
     */
    create(data) {
      return client.post('/doctor/examinations', data).then((r) => r.data);
    },

    /**
     * PUT /api/doctor/examinations/:id
     * @param {number} id
     * @param {{ chief_complaint?: string, clinical_notes?: string, examined_at?: string }} data
     * @returns {Promise<import('./types.js').Examination>}
     */
    update(id, data) {
      return client.put(`/doctor/examinations/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/doctor/examinations/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/doctor/examinations/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/examinations/:id/submit
     * Marks the examination as submitted (ready for prediction).
     * @param {number} id
     * @returns {Promise<{message: string, examination: import('./types.js').Examination}>}
     */
    submit(id) {
      return client.post(`/doctor/examinations/${id}/submit`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/examinations/:id/conclude
     * Marks the examination as concluded after prediction review.
     * @param {number} id
     * @param {{ doctor_conclusion: string }} data
     * @returns {Promise<{message: string, examination: import('./types.js').Examination}>}
     */
    conclude(id, data) {
      return client.post(`/doctor/examinations/${id}/conclude`, data).then((r) => r.data);
    },
  },

  // ─── WSI Uploads ─────────────────────────────────────────────────────────────

  wsiUploads: {
    /**
     * GET /api/doctor/wsi-uploads
     * @param {{ patient_id?: number, status?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/doctor/wsi-uploads', { params }).then((r) => r.data);
    },

    /**
     * GET /api/doctor/wsi-uploads/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').WsiUpload>}
     */
    get(id) {
      return client.get(`/doctor/wsi-uploads/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/wsi-uploads  (multipart/form-data)
     * Accepted formats: tiff, svs, ndpi, scn, mrxs, vms, vmu, bif, btf (max 2 GB)
     * @param {{ patient_id: number, file: File }} data
     * @param {Function} [onUploadProgress] - axios progress callback
     * @returns {Promise<import('./types.js').WsiUpload>}
     */
    upload({ patient_id, file }, onUploadProgress) {
      const form = new FormData();
      form.append('patient_id', patient_id);
      form.append('file', file);
      return client
        .post('/doctor/wsi-uploads', form, {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress,
        })
        .then((r) => r.data);
    },

    /**
     * DELETE /api/doctor/wsi-uploads/:id
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/doctor/wsi-uploads/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/wsi-uploads/:id/extract-features
     * Triggers feature extraction on the uploaded WSI file.
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    extractFeatures(id) {
      return client.post(`/doctor/wsi-uploads/${id}/extract-features`).then((r) => r.data);
    },
  },

  // ─── Predictions ─────────────────────────────────────────────────────────────

  predictions: {
    /**
     * GET /api/doctor/predictions
     * @param {{ examination_id?: number, status?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/doctor/predictions', { params }).then((r) => r.data);
    },

    /**
     * GET /api/doctor/predictions/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Prediction>}
     */
    get(id) {
      return client.get(`/doctor/predictions/${id}`).then((r) => r.data);
    },

    /**
     * GET /api/doctor/predictions/:id/status
     * Lightweight polling endpoint.
     * @param {number} id
     * @returns {Promise<{id: number, status: string, is_lum_a: boolean|null, confidence_lum_a: number|null, confidence_non_lum_a: number|null, failure_reason: string|null}>}
     */
    getStatus(id) {
      return client.get(`/doctor/predictions/${id}/status`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/predictions/predict
     * Dispatch a new AI prediction job.
     * @param {{ examination_id: number, wsi_upload_id?: number }} data
     * @returns {Promise<{message: string, prediction: import('./types.js').Prediction}>}
     */
    predict(data) {
      return client.post('/doctor/predictions/predict', data).then((r) => r.data);
    },

    /**
     * POST /api/doctor/predictions/:id/retry
     * Retry a failed prediction.
     * @param {number} id
     * @returns {Promise<{message: string, prediction: import('./types.js').Prediction}>}
     */
    retry(id) {
      return client.post(`/doctor/predictions/${id}/retry`).then((r) => r.data);
    },

    /**
     * GET /api/doctor/predictions/:id/xai
     * Get XAI (explainability) results for a prediction.
     * @param {number} predictionId
     * @returns {Promise<import('./types.js').XaiResult>}
     */
    getXai(predictionId) {
      return client.get(`/doctor/predictions/${predictionId}/xai`).then((r) => r.data);
    },
  },

  // ─── Reports ─────────────────────────────────────────────────────────────────

  reports: {
    /**
     * GET /api/doctor/reports
     * @param {{ examination_id?: number, status?: string, page?: number }} params
     * @returns {Promise<import('./types.js').PaginatedResponse>}
     */
    list(params = {}) {
      return client.get('/doctor/reports', { params }).then((r) => r.data);
    },

    /**
     * GET /api/doctor/reports/:id
     * @param {number} id
     * @returns {Promise<import('./types.js').Report>}
     */
    get(id) {
      return client.get(`/doctor/reports/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/reports
     * @param {{ examination_id: number, prediction_id: number, notes?: string }} data
     * @returns {Promise<import('./types.js').Report>}
     */
    create(data) {
      return client.post('/doctor/reports', data).then((r) => r.data);
    },

    /**
     * PUT /api/doctor/reports/:id
     * @param {number} id
     * @param {{ notes?: string }} data
     * @returns {Promise<import('./types.js').Report>}
     */
    update(id, data) {
      return client.put(`/doctor/reports/${id}`, data).then((r) => r.data);
    },

    /**
     * DELETE /api/doctor/reports/:id
     * Can only delete draft reports.
     * @param {number} id
     * @returns {Promise<{message: string}>}
     */
    delete(id) {
      return client.delete(`/doctor/reports/${id}`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/reports/:id/finalize
     * Locks the report — no further edits allowed.
     * @param {number} id
     * @returns {Promise<{message: string, report: import('./types.js').Report}>}
     */
    finalize(id) {
      return client.post(`/doctor/reports/${id}/finalize`).then((r) => r.data);
    },

    /**
     * POST /api/doctor/reports/:id/attach-file  (multipart/form-data)
     * Attach a PDF file to the report (max 20 MB).
     * @param {number} id
     * @param {File} file
     * @returns {Promise<{message: string, file_path: string}>}
     */
    attachFile(id, file) {
      const form = new FormData();
      form.append('file', file);
      return client
        .post(`/doctor/reports/${id}/attach-file`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then((r) => r.data);
    },
  },
};

export default doctor;
