export type VoterMapMarker = {
    id: number;
    name: string;
    address: string;
    neighborhoodId: number | null;
    neighborhood: string | null;
    latitude: number;
    longitude: number;
};

export type VoterMapSummary = {
    totalVoters: number;
    locatedVoters: number;
    withoutLocation: number;
    truncated: boolean;
};
