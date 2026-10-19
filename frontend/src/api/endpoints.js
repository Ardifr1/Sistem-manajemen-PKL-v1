/**
 * endpoints.js
 * Pemetaan path API selaras dengan backend Laravel (backend/routes/api.php).
 * Base path: /api — semua endpoint di dalam middleware auth:sanctum,
 * kecuali `login`.
 *
 * File ini dipakai BERSAMA client.js ketika USE_MOCK = false.
 * Selama mock aktif, file ini tidak dipakai halaman mana pun.
 */

const id = (base) => (x) => `${base}/${x}`;

export const ep = {
  auth: {
    login: "/login",
    logout: "/logout",
    forgotPassword: "/forgot-password",
    setPassword: "/set-password",
    changePassword: "/change-password",
  },
  companies: {
    index: "/companies",
    show: id("/companies"),
    store: "/companies",
    update: id("/companies"),
    destroy: id("/companies"),
  },
  schools: {
    index: "/schools",
    store: "/schools",
    update: id("/schools"),
    destroy: id("/schools"),
  },
  companySupervisors: {
    index: "/company-supervisors",
    show: id("/company-supervisors"),
    store: "/company-supervisors",
    update: id("/company-supervisors"),
    destroy: id("/company-supervisors"),
  },
  // Keanggotaan perusahaan eksplisit (PRD bagian C).
  companyMemberships: {
    index: "/company-memberships",
    show: id("/company-memberships"),
    store: "/company-memberships",
    update: id("/company-memberships"),
    destroy: id("/company-memberships"),
  },
  // Pengajuan perusahaan nonmitra oleh siswa + verifikasi admin (PRD bagian D).
  companyProposals: {
    index: "/company-proposals",
    show: id("/company-proposals"),
    store: "/company-proposals",
    verify: (companyId) => `/company-proposals/${companyId}/verify`,
  },
  periods: {
    index: "/pkl-periods",
    show: id("/pkl-periods"),
    store: "/pkl-periods",
    update: id("/pkl-periods"),
    destroy: id("/pkl-periods"),
  },
  applications: {
    index: "/pkl-applications",
    show: id("/pkl-applications"),
    store: "/pkl-applications",
    update: id("/pkl-applications"),
    destroy: id("/pkl-applications"),
    // Pilihan final siswa (bila diterima beberapa perusahaan, pilih satu).
    choose: (applicationId) => `/pkl-applications/${applicationId}/choose`,
  },
  applicationDocuments: {
    index: "/application-documents",
    show: id("/application-documents"),
    store: "/application-documents",
    update: id("/application-documents"),
    destroy: id("/application-documents"),
    download: (docId) => `/application-documents/${docId}/download`,
  },
  applicationReviews: {
    index: "/application-reviews",
    show: id("/application-reviews"),
    store: "/application-reviews",
    update: id("/application-reviews"),
    destroy: id("/application-reviews"),
  },
  interviews: {
    index: "/interviews",
    show: id("/interviews"),
    store: "/interviews",
    update: id("/interviews"),
    destroy: id("/interviews"),
  },
  placements: {
    index: "/pkl-placements",
    show: id("/pkl-placements"),
    store: "/pkl-placements",
    update: id("/pkl-placements"),
    destroy: id("/pkl-placements"),
  },
  journals: {
    index: "/journals",
    show: id("/journals"),
    store: "/journals",
    update: id("/journals"),
    destroy: id("/journals"),
    submit: (journalId) => `/journals/${journalId}/submit`,
    verify: (journalId) => `/journals/${journalId}/verify`,
  },
  attendances: {
    index: "/attendances",
    show: id("/attendances"),
    store: "/attendances",
    update: id("/attendances"),
    destroy: id("/attendances"),
  },
  feedbacks: {
    index: "/feedbacks",
    show: id("/feedbacks"),
    store: "/feedbacks",
    update: id("/feedbacks"),
    destroy: id("/feedbacks"),
    review: (feedbackId) => `/feedbacks/${feedbackId}/review`,
  },
  assessmentComponents: {
    index: "/assessment-components",
    show: id("/assessment-components"),
    store: "/assessment-components",
    update: id("/assessment-components"),
    destroy: id("/assessment-components"),
  },
  assessments: {
    index: "/assessments",
    show: id("/assessments"),
    store: "/assessments",
    update: id("/assessments"),
    destroy: id("/assessments"),
  },
  finalAssessments: {
    index: "/final-assessments",
    show: id("/final-assessments"),
    store: "/final-assessments/calculate",
    update: id("/final-assessments"),
    destroy: id("/final-assessments"),
    calculate: "/final-assessments/calculate",
    publish: (fid) => `/final-assessments/${fid}/publish`,
    unpublish: (fid) => `/final-assessments/${fid}/unpublish`,
  },
  auditLogs: {
    index: "/audit-logs",
    show: id("/audit-logs"),
  },
  studentProfiles: {
    index: "/student-profiles",
    show: id("/student-profiles"),
    store: "/student-profiles",
    update: id("/student-profiles"),
    destroy: id("/student-profiles"),
  },
  users: {
    index: "/users",
    show: id("/users"),
    store: "/users",
    update: id("/users"),
    destroy: id("/users"),
    resendAccount: (uid) => `/users/${uid}/resend-account`,
    resetRequests: "/password-reset-requests",
    resolveResetRequest: id("/password-reset-requests"),
  },
};

export default ep;
