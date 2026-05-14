# LumA API Client

Drop this folder into your React project. It gives you a fully typed, role-aware API client built on Axios.

## Installation

Copy the `api-client/` folder into your React project's `src/` directory.

Install Axios in your React project:
```bash
npm install axios
```

## Configuration

Set your backend URL in your `.env` file:
```env
# Vite
VITE_API_URL=http://localhost:5000/api

# CRA
REACT_APP_API_URL=http://localhost:5000/api
```

If no variable is set, the client defaults to `http://localhost:5000/api`.

## Authentication Flow

```js
import { auth, setAuthToken } from './api-client';

// Step 1 – submit credentials
await auth.login({ email, password });

// Step 2 – request OTP
await auth.sendOtp({ email, method: 'email' }); // or 'sms'

// Step 3 – verify OTP (token is saved automatically)
const { user } = await auth.verifyOtp({ email, otp: '123456' });

// Get the current user
const me = await auth.me();

// Logout
await auth.logout();
```

The Bearer token is stored in `localStorage` under `auth_token` and attached to every request automatically. On a 401 response a global `auth:logout` event is dispatched — listen to it to redirect to your login page:

```js
window.addEventListener('auth:logout', () => navigate('/login'));
```

## Usage by Role

### Doctor

```js
import { doctor } from './api-client';

// Patients
const patients   = await doctor.patients.list({ search: 'TCGA' });
const patient    = await doctor.patients.get(1);
const newPatient = await doctor.patients.create({ patient_identifier: 'TCGA-XX-0001', er_status: true, pr_status: false, her2_binary: false, age: 45, stage_num: 2 });

// Examinations
const exam = await doctor.examinations.create({ patient_id: 1, clinical_notes: '...' });
await doctor.examinations.submit(exam.id);

// WSI Uploads
const upload = await doctor.wsiUploads.upload({ patient_id: 1, file: fileObject });
await doctor.wsiUploads.extractFeatures(upload.id);

// Predictions
const { prediction } = await doctor.predictions.predict({ examination_id: exam.id, wsi_upload_id: upload.id });
const status = await doctor.predictions.getStatus(prediction.id);  // poll this
const xai    = await doctor.predictions.getXai(prediction.id);

// Conclude & report
await doctor.examinations.conclude(exam.id, { doctor_conclusion: 'Luminal A confirmed.' });
const report = await doctor.reports.create({ examination_id: exam.id, prediction_id: prediction.id });
await doctor.reports.finalize(report.id);

// Insights
const kpis     = await doctor.insights.kpis();
const activity = await doctor.insights.recentActivity();
```

### Org Manager

```js
import { orgManager } from './api-client';

const dashboard = await orgManager.getDashboard();
const members   = await orgManager.members.list();
await orgManager.members.approve(userId);

const plans = await orgManager.payments.getPlans();
const { checkout_url } = await orgManager.payments.subscribe({ plan_id: 2, duration_months: 12 });
window.location.href = checkout_url;  // redirect to Chargily
```

### Admin

```js
import { admin } from './api-client';

const orgs  = await admin.organizations.list({ status: 'pending' });
await admin.organizations.approve(orgId);

const users = await admin.users.list({ role: 'doctor' });
await admin.users.deactivate(userId);

const models = await admin.aiModels.list();
await admin.aiModels.activate(modelId);

const kpis = await admin.insights.kpis();
```

### Instructor (Federated Learning)

```js
import { instructor } from './api-client';

const rounds = await instructor.rounds.list();
const round  = await instructor.rounds.create({ ai_model_id: 1 });
await instructor.contributions.create({ fl_round_id: round.id, organization_id: 3, local_sample_size: 200, local_accuracy_before: 0.82, local_accuracy_after: 0.87, weights_update_path: 'weights/org3_r1.pt' });
await instructor.rounds.complete(round.id, { global_accuracy: 0.89 });

const kpis = await instructor.insights.kpis();
```

## Error Handling

All methods return Promises and throw Axios errors on non-2xx responses. The error body is in `error.response.data`:

```js
try {
  await doctor.patients.create(data);
} catch (error) {
  if (error.response?.status === 422) {
    console.log(error.response.data.errors); // Laravel validation errors
  }
}
```

## File Structure

```
api-client/
├── client.js       – Axios instance, token management
├── auth.js         – Public + shared auth endpoints
├── admin.js        – Admin: insights, orgs, users, AI models, audit logs
├── doctor.js       – Doctor: patients, examinations, WSI, predictions, XAI, reports
├── orgManager.js   – Org Manager: dashboard, insights, members, payments
├── instructor.js   – Instructor: FL models, rounds, contributions
├── types.js        – JSDoc type definitions for all API objects
└── index.js        – Single barrel export
```
