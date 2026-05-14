export { default as client, setAuthToken, getAuthToken } from './client.js';
export { default as auth } from './auth.js';
export { default as admin } from './admin.js';
export { default as doctor } from './doctor.js';
export { default as orgManager } from './orgManager.js';
export { default as instructor } from './instructor.js';

import auth from './auth.js';
import admin from './admin.js';
import doctor from './doctor.js';
import orgManager from './orgManager.js';
import instructor from './instructor.js';

const api = { auth, admin, doctor, orgManager, instructor };
export default api;
