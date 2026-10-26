/**
 * index.js — Pintu masuk resmi lapisan API frontend.
 *
 * ============================================================================
 * CARA PAKAI (di halaman/komponen):
 *   import { usersApi, companiesApi } from "../../api/index.js";
 *   const [rows, setRows] = useState([]);
 *   useEffect(() => { usersApi.index().then((res) => setRows(res.data)); }, []);
 *   // res selalu berbentuk { message, data } seperti backend Laravel.
 * ============================================================================
 * CARA MENGHUBUNGKAN BACKEND ASLI:
 *   1. Set VITE_USE_MOCK=false di file .env (jangan edit file ini —
 *      nilainya ikut ke-reset setiap git pull).
 *   2. Set VITE_API_URL di file .env ke alamat backend Laravel
 *      (mis. VITE_API_URL=http://localhost:8000/api).
 *   3. Login lewat halaman login — token tersimpan otomatis di
 *      localStorage 'simagang_token' oleh live/auth.js.
 * Halaman-halaman TIDAK PERLU diubah — signature fungsi mock dan live
 * sama persis, yang berganti hanya sumber datanya.
 * ============================================================================
 */

// Dibaca dari env agar tidak ke-reset saat git pull.
// Default: false (live, konek backend). Set VITE_USE_MOCK=true untuk mode mock.
export const USE_MOCK = import.meta.env.VITE_USE_MOCK === "true";

import * as authMock from "./mock/auth.js";
import * as authLive from "./live/auth.js";
import * as companiesMock from "./mock/companies.js";
import * as companiesLive from "./live/companies.js";
import * as companyProposalsMock from "./mock/companyProposals.js";
import * as companyProposalsLive from "./live/companyProposals.js";
import * as companyMembershipsMock from "./mock/companyMemberships.js";
import * as companyMembershipsLive from "./live/companyMemberships.js";
import * as periodsMock from "./mock/periods.js";
import * as periodsLive from "./live/periods.js";
import * as applicationsMock from "./mock/applications.js";
import * as applicationsLive from "./live/applications.js";
import * as placementsMock from "./mock/placements.js";
import * as placementsLive from "./live/placements.js";
import * as journalsMock from "./mock/journals.js";
import * as interviewsMock from "./mock/interviews.js";
import * as journalsLive from "./live/journals.js";
import * as interviewsLive from "./live/interviews.js";
import * as attendancesMock from "./mock/attendances.js";
import * as attendancesLive from "./live/attendances.js";
import * as progressMock from "./mock/progress.js";
import * as progressLive from "./live/progress.js";
import * as feedbacksMock from "./mock/feedbacks.js";
import * as feedbacksLive from "./live/feedbacks.js";
import * as assessmentsMock from "./mock/assessments.js";
import * as assessmentsLive from "./live/assessments.js";
import * as profilesMock from "./mock/profiles.js";
import * as profilesLive from "./live/profiles.js";
import * as usersMock from "./mock/users.js";
import * as usersLive from "./live/users.js";
import * as dashboardMock from "./mock/dashboard.js";
import * as schoolsMock from "./mock/schools.js";
import * as aiMock from "./mock/ai.js";
import * as dashboardLive from "./live/dashboard.js";
import * as schoolsLive from "./live/schools.js";
import * as aiLive from "./live/ai.js";

const pick = (mockMod, liveMod) => (USE_MOCK ? mockMod : liveMod);

export const authApi = pick(authMock, authLive);
export const companiesApi = pick(companiesMock, companiesLive);
export const periodsApi = pick(periodsMock, periodsLive);
export const applicationsApi = pick(applicationsMock, applicationsLive);
export const placementsApi = pick(placementsMock, placementsLive);
export const journalsApi = pick(journalsMock, journalsLive);
export const interviewsApi = pick(interviewsMock, interviewsLive);
export const attendancesApi = pick(attendancesMock, attendancesLive);
export const feedbacksApi = pick(feedbacksMock, feedbacksLive);
export const assessmentsApi = pick(assessmentsMock, assessmentsLive);
export const componentsApi = pick(assessmentsMock, assessmentsLive).componentsApi;
export const finalsApi = pick(assessmentsMock, assessmentsLive).finalsApi;
export const auditLogsApi = pick(assessmentsMock, assessmentsLive).auditLogsApi;
export const finalAssessmentsApi = pick(assessmentsMock, assessmentsLive).finalAssessmentsApi;
export const profilesApi = pick(profilesMock, profilesLive);
export const usersApi = pick(usersMock, usersLive);
export const dashboardApi = pick(dashboardMock, dashboardLive);
export const schoolsApi = pick(schoolsMock, schoolsLive);
export const aiApi = pick(aiMock, aiLive);

// Sub-modul bernama (bentuk alternatif akses) — mengikuti flag yang sama.
export const supervisorsApi = pick(companiesMock, companiesLive).supervisorsApi;
export const companyProposalsApi = pick(companyProposalsMock, companyProposalsLive);
export const companyMembershipsApi = pick(companyMembershipsMock, companyMembershipsLive);
export const documentsApi = pick(applicationsMock, applicationsLive).documentsApi;
export const reviewsApi = pick(applicationsMock, applicationsLive).reviewsApi;

// Konstanta label (tidak terkait sumber data).
export { ROLE_LABEL } from "./mock/users.js";
