/** live/assessments.js — assessments + assessment-components + final-assessments. */
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";
import { client } from "../client.js";

export const { index, show, store, update, destroy } = liveCrud(ep.assessments);
export const componentsApi = liveCrud(ep.assessmentComponents);
export const finalsApi = {
  ...liveCrud(ep.finalAssessments),
  publish: (id) => client.post(ep.finalAssessments.publish(id)),
  unpublish: (id, reason) => client.post(ep.finalAssessments.unpublish(id), { reason }),
};
export const auditLogsApi = {
  index: (params = {}) => {
    const s = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== "")
    ).toString();
    return client.get(ep.auditLogs.index + (s ? `?${s}` : ""));
  },
  show: (id) => client.get(ep.auditLogs.show(id)),
};
