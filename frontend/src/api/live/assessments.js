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
  unpublish: (id, note) => client.post(ep.finalAssessments.unpublish(id), { note }),
};
