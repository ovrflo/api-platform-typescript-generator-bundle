import {HydraCollection, HydraItem} from "./interfaces/ApiTypes";

let apiToken: string|null|undefined = null;
let apiUrl: string|null = '__API_BASE_URL__';
export const configStore = {
    getToken(): string|null|undefined {
        return apiToken;
    },
    setToken(token: string|null|undefined): void {
        apiToken = token;
    },
    getApiUrl(): string|null {
        return apiUrl;
    },
    setApiUrl(url: string|null): void {
        apiUrl = url;
    },
};

export interface RequestConfig extends RequestInit {}

export type CreateResult<Type> = Promise<Type>;
export type ReadResult<Type> = Promise<Type>;
export type UpdateResult<Type> = Promise<Type>;
export type ReplaceResult<Type> = Promise<Type>;
export type DeleteResult<Type> = Promise<Type>;
export type ListResponsePromise<Type extends HydraItem> = Promise<HydraCollection<Type>>;

export type ListEndpoint<Type extends HydraItem, ListParamsType> = (params?: ListParamsType, config?: RequestConfig) => ListResponsePromise<Type>;
export type CreateEndpoint<InputType, OutputType = InputType> = (object: InputType, config?: RequestConfig) => CreateResult<OutputType>;
export type ReadEndpoint<Type> = (id: number|string, config?: RequestConfig) => ReadResult<Type>;
export type UpdateEndpoint<InputType, OutputType = InputType> = (id: number|string, object: InputType, config?: RequestConfig) => UpdateResult<OutputType>;
export type ReplaceEndpoint<InputType, OutputType = InputType> = (id: number|string, object: InputType, config?: RequestConfig) => ReplaceResult<OutputType>;
export type DeleteEndpoint<Type> = (id: number|string, config?: RequestConfig) => DeleteResult<Type>;

export type CRUD<Type extends HydraItem, ListParamsType> = {
    create: CreateEndpoint<Type>,
    read: ReadEndpoint<Type>,
    update: UpdateEndpoint<Type>,
    replace: UpdateEndpoint<Type>,
    delete: DeleteEndpoint<Type>,
    list: ListEndpoint<Type, ListParamsType>,
};

function doFetch(input: RequestInfo, config?: RequestConfig): Promise<Response>
{
    config = config ?? {};
    config.headers = config.headers ?? {};
    if (apiToken) {
        // @ts-ignore
        config.headers['Authorization'] = 'Bearer ' + apiToken;
    }
    return fetch(input, config);
}

function doFetchJson(input: RequestInfo, config?: RequestConfig): Promise<any>
{
    return doFetch(input, config).then((response) => response.json());
}

function replaceParams(endpoint: string, params: Record<string, any>): { url: string, otherParams: any } {
    params ??= {};
    let usedParams: string[] = [];
    let url = endpoint.replace(/\{([a-zA-Z0-9_-]+)\}/gim, (match) => {
        let key = match.replace(/^\{/, '').replace(/\}$/, '');
        let isOptional = false;
        if (/\?$/.test(key)) {
            isOptional = true;
            key = key.slice(0, -1);
        }
        let value = params[key];
        if (value === undefined) {
            if (!isOptional) {
                throw new Error('Parameter "' + key + '" is mandatory.');
            } else {
                value = '';
            }
        } else if (typeof value !== 'number') {
            value = encodeURIComponent(value.toString());
        }

        usedParams.push(key);

        return value;
    });

    let otherParams: Record<string, any> = {};
    Object.keys(params).filter((key: string) => !usedParams.includes(key)).forEach((key: string) => otherParams[key] = params[key]);

    return { url, otherParams };
}

function buildUrl(endpoint: string, params?: any): string {
    let url = new URL(apiUrl + endpoint);
    for (let [k, v] of Object.entries(params)) {
        if (Array.isArray(v)) {
            v.forEach((item) => {
                url.searchParams.append(k + '[]', item);
            })
        } else if (undefined !== v) {
            url.searchParams.set(k, v as string);
        }
    }

    return url.toString();
}

export function list<Type extends HydraItem, ListParamsType>(endpoint: string, defaultConfig: RequestConfig = {}): ListEndpoint<Type, ListParamsType> {
    return (params?: ListParamsType, config?: RequestConfig): ListResponsePromise<Type> => {
        params = params ?? {} as ListParamsType;
        return doFetchJson(
            buildUrl(endpoint, params),
            { method: 'GET', ...defaultConfig, ...config }
        ) as unknown as ListResponsePromise<Type>;
    }
}

export function create<InputType, OutputType = InputType>(endpoint: string, defaultConfig: RequestConfig = {}): CreateEndpoint<InputType, OutputType> {
    return (object: InputType, config?: RequestConfig): CreateResult<OutputType> => {
        return doFetchJson(
            endpoint,
            { method: 'POST', body: JSON.stringify(object), headers: { 'Content-Type': 'application/json' }, ...defaultConfig, ...config },
        ) as unknown as CreateResult<OutputType>;
    }
}

export function read<Type>(endpoint: string, defaultConfig: RequestConfig = {}): ReadEndpoint<Type> {
    return (id: number|string, config?: RequestConfig): ReadResult<Type> => {
        return doFetchJson(
            endpoint + '/' + id,
            { method: 'GET', ...defaultConfig, ...config },
        ) as unknown as ReadResult<Type>;
    }
}

export function update<InputType, OutputType = InputType>(endpoint: string, defaultConfig: RequestConfig = {}): UpdateEndpoint<InputType, OutputType> {
    return (id: number|string, object: InputType, config?: RequestConfig): UpdateResult<OutputType> => {
        return doFetchJson(
            endpoint + '/' + id,
            { method: 'PUT', body: JSON.stringify(object), headers: { 'Content-Type': 'application/json' }, ...defaultConfig, ...config },
        ) as unknown as UpdateResult<OutputType>;
    }
}

export function replace<InputType, OutputType = InputType>(endpoint: string, defaultConfig?: RequestConfig): ReplaceEndpoint<InputType, OutputType> {
    defaultConfig = defaultConfig ?? {} as RequestConfig;
    return (id: number|string, object: InputType, config?: RequestConfig): ReplaceResult<OutputType> => {
        return doFetchJson(
            endpoint + '/' + id,
            { method: 'PUT', body: JSON.stringify(object), headers: { 'Content-Type': 'application/json' }, ...defaultConfig, ...config },
        ) as unknown as ReplaceResult<OutputType>;
    }
}

export function remove<Type>(endpoint: string, defaultConfig: RequestConfig = {}): DeleteEndpoint<Type> {
    return (id: number|string, config?: RequestConfig): DeleteResult<Type> => {
        return doFetchJson(
            endpoint + '/' + id,
            { method: 'DELETE', ...defaultConfig, ...config },
        ) as unknown as DeleteResult<Type>;
    }
}

export function request<Request, Result>(method: string, endpoint: string, defaultConfig: RequestConfig = {}): ((params?: any, body?: Request, config?: RequestConfig) => Promise<Result>) {
    return (async (params?: any, body?: Request, config?: RequestConfig): Promise<Result> => {
        let processedEndpoint = replaceParams(endpoint, params);
        let url = buildUrl(processedEndpoint.url, processedEndpoint.otherParams);

        if (undefined !== body) {
            (config ?? {}).body = JSON.stringify(body);
        }

        return await doFetchJson(
            url,
            { method: method, ...defaultConfig, ...config },
        ) as unknown as Promise<Result>;
    })
}

export function crud<Type extends HydraItem, ListParamsType>(endpoint: string): CRUD<Type, ListParamsType> {
    return {
        create: create<Type>(endpoint),
        read: read<Type>(endpoint),
        list: list<Type, ListParamsType>(endpoint),
        replace: update<Type>(endpoint),
        update: update<Type>(endpoint),
        delete: remove<Type>(endpoint),
    }
}
