export type Repository = {
    id: number;
    github_id: number;
    full_name: string;
    default_branch: string | null;
    index_status: 'pending' | 'indexing' | 'indexed' | 'failed';
    indexed_sha: string | null;
    indexed_at: string | null;
    index_error: string | null;
    index_files_total: number | null;
    index_files_done: number | null;
    chunks_count: number;
    embedded_chunks_count: number;
};

export type McpServer = {
    id: number;
    name: string;
    url: string;
    auth: 'none' | 'token' | 'oauth';
    connected: boolean;
};

export type ConversationSummary = {
    id: string;
    title: string;
    updated_at: string;
};

export type GitHubAccount = {
    key: string;
    label: string;
    repositories: { id: number; full_name: string; private: boolean }[];
    error: string | null;
};

export type ChatSourceServer = {
    id: number;
    name: string;
    auth: McpServer['auth'];
    connected: boolean;
    isNew: boolean;
};

export type ChatSources = {
    repositories: number;
    mcpServers: ChatSourceServer[];
};
