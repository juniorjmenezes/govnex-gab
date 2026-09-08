export type ElectoralMapPoint = {
    id: number;
    name: string;
    address: string | null;
    neighborhood: string | null;
    latitude: number;
    longitude: number;
    votes: number;
    /** Quantidade de seções eleitorais agregadas neste local. */
    sections: number;
};

export type ElectoralMapSummary = {
    configured: boolean;
    reason: 'number_missing' | 'candidate_unmatched' | null;
    candidate: {
        name: string;
        party: string | null;
        number: string | null;
    } | null;
    election: {
        name: string;
        year: number;
    } | null;
    totalVotes: number;
    totalLocations: number;
    locatedLocations: number;
    pendingGeocoding: number;
};
