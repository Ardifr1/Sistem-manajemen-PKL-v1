/** live/assessments.js — assessments + assessment-components + final-assessments. */
import { client } from "../client.js";
import { ep } from "../endpoints.js";
import { liveCrud } from "./_shared.js";

export const { index, show, store, update, destroy } = liveCrud(ep.assessments);
export const componentsApi = liveCrud(ep.assessmentComponents);
export const finalAssessmentsApi = {
  ...liveCrud(ep.finalAssessments),
  calculate: (placement_id) => client.post(ep.finalAssessments.calculate, { placement_id }),
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
export const finalsApi = finalAssessmentsApi;
