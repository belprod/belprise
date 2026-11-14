/** Version statuses (§5.4): labels shared by the admin and the client pages. */
export type VersionStatus = 'in_progress' | 'to_review' | 'changes_requested' | 'approved';

export const VERSION_STATUS: Record<VersionStatus, string> = {
  in_progress: 'En cours',
  to_review: 'À valider',
  changes_requested: 'Modifications demandées',
  approved: 'Validé',
};
