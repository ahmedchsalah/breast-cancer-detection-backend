/**
 * ─────────────────────────────────────────────────
 *  LumA API — JSDoc Type Definitions
 *  Use these as reference for all API responses.
 * ─────────────────────────────────────────────────
 */

/**
 * @typedef {Object} User
 * @property {number} id
 * @property {string} name
 * @property {string} email
 * @property {string|null} phone_number
 * @property {string|null} avatar
 * @property {boolean} is_active
 * @property {number|null} organization_id
 * @property {Organization|null} organization
 * @property {Role[]} roles
 * @property {string} created_at
 */

/**
 * @typedef {Object} Role
 * @property {number} id
 * @property {string} name  - "admin" | "org_manager" | "doctor" | "instructor"
 */

/**
 * @typedef {Object} Organization
 * @property {number} id
 * @property {string} name
 * @property {string} type  - "clinic" | "hospital" | "laboratory" | "radiology_center"
 * @property {string} status  - "pending" | "active" | "rejected" | "suspended"
 * @property {string|null} contact_email
 * @property {string|null} address
 * @property {number|null} latitude
 * @property {number|null} longitude
 * @property {number|null} plan_id
 * @property {string|null} subscription_status
 * @property {string|null} subscription_ends_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} Plan
 * @property {number} id
 * @property {string} name
 * @property {string} slug
 * @property {string|null} description
 * @property {number} price
 * @property {number} max_doctors
 * @property {number} max_predictions_per_month
 * @property {boolean} fl_contribution_allowed
 * @property {boolean} instructor_allowed
 * @property {boolean} is_active
 */

/**
 * @typedef {Object} Patient
 * @property {number} id
 * @property {number} organization_id
 * @property {string} patient_identifier
 * @property {boolean} er_status
 * @property {boolean} pr_status
 * @property {boolean} her2_binary
 * @property {number} age
 * @property {number} stage_num  - 1 | 2 | 3 | 4
 * @property {boolean} er_status_missing
 * @property {boolean} pr_status_missing
 * @property {number|null} fraction_genome_altered
 * @property {number|null} buffa_hypoxia_score
 * @property {number|null} ragnum_hypoxia_score
 * @property {number|null} winter_hypoxia_score
 * @property {string} created_at
 */

/**
 * @typedef {Object} Examination
 * @property {number} id
 * @property {number} patient_id
 * @property {number} doctor_id
 * @property {number} organization_id
 * @property {string|null} chief_complaint
 * @property {string|null} clinical_notes
 * @property {string|null} doctor_conclusion
 * @property {string} status  - "draft" | "submitted" | "predicted" | "concluded"
 * @property {string|null} examined_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} WsiUpload
 * @property {number} id
 * @property {number} patient_id
 * @property {number} uploaded_by
 * @property {number} organization_id
 * @property {string} file_path
 * @property {string} original_name
 * @property {number} file_size_bytes
 * @property {string} mime_type
 * @property {string} status  - "pending" | "processing" | "ready" | "failed"
 * @property {string|null} features_path
 * @property {string|null} features_extracted_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} Prediction
 * @property {number} id
 * @property {number} examination_id
 * @property {number} patient_id
 * @property {number} ai_model_id
 * @property {number|null} wsi_upload_id
 * @property {number|null} organization_id
 * @property {boolean|null} is_lum_a
 * @property {number|null} confidence_lum_a
 * @property {number|null} confidence_non_lum_a
 * @property {Object|null} clinical_input_snapshot
 * @property {string} status  - "pending" | "processing" | "completed" | "failed"
 * @property {string|null} job_id
 * @property {string|null} failure_reason
 * @property {string|null} completed_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} XaiResult
 * @property {number} id
 * @property {number} prediction_id
 * @property {string|null} heatmap_path
 * @property {string} heatmap_status  - "pending" | "processing" | "completed" | "failed"
 * @property {string|null} shap_plot_path
 * @property {string} shap_status  - "pending" | "processing" | "completed" | "failed"
 * @property {Object|null} shap_values
 * @property {Object|null} top_features
 * @property {string} created_at
 */

/**
 * @typedef {Object} Report
 * @property {number} id
 * @property {number} examination_id
 * @property {number} prediction_id
 * @property {number} patient_id
 * @property {number} doctor_id
 * @property {number} organization_id
 * @property {string|null} file_path
 * @property {string|null} file_name
 * @property {string|null} notes
 * @property {string} status  - "draft" | "final"
 * @property {string} created_at
 */

/**
 * @typedef {Object} AiModel
 * @property {number} id
 * @property {string} name
 * @property {string} slug
 * @property {string} version
 * @property {string} inference_type
 * @property {string|null} description
 * @property {boolean} is_active
 * @property {number|null} auc
 * @property {number|null} accuracy
 * @property {number|null} sensitivity
 * @property {number|null} specificity
 * @property {number|null} f1_score
 * @property {number} n_checkpoints
 * @property {number|null} threshold
 * @property {string} created_at
 */

/**
 * @typedef {Object} FlRound
 * @property {number} id
 * @property {number} ai_model_id
 * @property {number} round_number
 * @property {string} status  - "pending" | "in_progress" | "completed" | "failed"
 * @property {number|null} global_accuracy
 * @property {string|null} started_at
 * @property {string|null} ended_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} FlContribution
 * @property {number} id
 * @property {number} fl_round_id
 * @property {number} organization_id
 * @property {number} local_sample_size
 * @property {number} local_accuracy_before
 * @property {number} local_accuracy_after
 * @property {string} weights_update_path
 * @property {string} created_at
 */

/**
 * @typedef {Object} Payment
 * @property {number} id
 * @property {number} organization_id
 * @property {number|null} subscription_id
 * @property {number|null} plan_id
 * @property {number} amount
 * @property {string} currency
 * @property {string} status  - "pending" | "completed" | "failed"
 * @property {string|null} payment_method
 * @property {string|null} transaction_id
 * @property {string|null} chargily_checkout_id
 * @property {string|null} checkout_url
 * @property {number} duration_months
 * @property {string} created_at
 */

/**
 * @typedef {Object} Subscription
 * @property {number} id
 * @property {number} organization_id
 * @property {number} plan_id
 * @property {string} status  - "active" | "expired" | "cancelled"
 * @property {string} starts_at
 * @property {string} ends_at
 * @property {string} created_at
 */

/**
 * @typedef {Object} AuditLog
 * @property {number} id
 * @property {number|null} user_id
 * @property {number|null} organization_id
 * @property {string} action
 * @property {string|null} auditable_type
 * @property {number|null} auditable_id
 * @property {Object|null} old_values
 * @property {Object|null} new_values
 * @property {string|null} ip_address
 * @property {string} created_at
 */

/**
 * @typedef {Object} PaginatedResponse
 * @property {Array} data
 * @property {number} current_page
 * @property {number} last_page
 * @property {number} per_page
 * @property {number} total
 * @property {string|null} next_page_url
 * @property {string|null} prev_page_url
 */

export {};
