import Foundation

struct CallAgent: Identifiable {
    let number: String
    let name: String
    var id: String { number }
}

struct APIPage {
    let calls: [CallRecord]
    let total: Int
    let kpi: [String: Any]
}

struct CallRecord: Identifiable {
    let id: String
    let callTime: String
    let status: String
    let clientNumber: String
    let clientName: String
    let didNumber: String
    let didLabel: String
    let queueNumber: String
    let queueLabel: String
    let agent: String
    let waitSeconds: Int
    let talkSeconds: Int
    let callID: String
    let recordingFile: String

    var hasRecording: Bool {
        // Incoming recordings are addressed by uniqueid (callid); the API may omit recording_file.
        // Outgoing recordings use the numeric outgoing_id and still require the recording marker.
        return talkSeconds > 0 && (!callID.isEmpty || (!recordingFile.isEmpty && (Int(id) ?? 0) > 0))
    }

    init(json: [String: Any], type: CallSection) {
        func string(_ key: String) -> String {
            guard let value = json[key], !(value is NSNull) else { return "" }
            return String(describing: value)
        }
        func integer(_ key: String) -> Int {
            if let number = json[key] as? NSNumber { return number.intValue }
            return Int(string(key)) ?? 0
        }
        let callIDValue = string("callid")
        let idValue = string("id")
        let time = string("call_time")
        self.id = idValue.isEmpty ? "\(callIDValue)-\(time)" : idValue
        self.callTime = time
        self.status = string("status")
        self.clientNumber = string("client_number")
        self.clientName = string("client_name")
        self.didNumber = string("did_number")
        self.didLabel = string("did_label")
        self.queueNumber = string("queue_number")
        self.queueLabel = string("queue_label")
        self.agent = type == .incoming ? string("agent_ext") : string("agent_number")
        self.waitSeconds = integer("wait_seconds")
        self.talkSeconds = integer("talk_seconds")
        self.callID = callIDValue
        self.recordingFile = string("recording_file")
    }

    var formattedTime: String {
        let input = ISO8601DateFormatter()
        input.formatOptions = [.withInternetDateTime, .withSpaceBetweenDateAndTime]
        if let date = input.date(from: callTime) {
            return date.formatted(date: .abbreviated, time: .shortened)
        }
        let formats = ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss"]
        for format in formats {
            let formatter = DateFormatter()
            formatter.locale = Locale(identifier: "en_US_POSIX")
            formatter.dateFormat = format
            if let date = formatter.date(from: callTime) {
                return date.formatted(date: .abbreviated, time: .shortened)
            }
        }
        return callTime
    }

    func duration(_ seconds: Int) -> String {
        String(format: "%02d:%02d", seconds / 60, seconds % 60)
    }
}

enum CallSection: String, CaseIterable, Identifiable, Equatable {
    case incoming, outgoing
    var id: String { rawValue }
    var title: String { self == .incoming ? "Входящие" : "Исходящие" }
    var icon: String { self == .incoming ? "phone.arrow.down.left" : "phone.arrow.up.right" }
}

struct StatusOption: Identifiable, Hashable {
    let value: String
    let title: String
    let colorKey: StatusColor
    var id: String { value }
}

enum StatusColor {
    case neutral, danger, success, warning, busy, info, nightCall, nightSelf
}

enum CallStatuses {
    static let incoming: [StatusOption] = [
        .init(value: "answered", title: "Отвеченные", colorKey: .neutral),
        .init(value: "missed_no_callback", title: "Пропущенные", colorKey: .danger),
        .init(value: "missed_callback_answered", title: "Перезвонили", colorKey: .success),
        .init(value: "missed_callback_noanswer", title: "Не дозвонились", colorKey: .warning),
        .init(value: "missed_callback_self", title: "Клиент перезвонил", colorKey: .info),
        .init(value: "miss_night_1", title: "Ночь: нужно перезвонить", colorKey: .nightCall),
        .init(value: "miss_night_2", title: "Ночь: перезвонит сам", colorKey: .nightSelf)
    ]
    static let outgoing: [StatusOption] = [
        .init(value: "ANSWERED", title: "Ответили", colorKey: .neutral),
        .init(value: "NO ANSWER", title: "Не ответили", colorKey: .warning),
        .init(value: "BUSY", title: "Занят", colorKey: .busy)
    ]

    static func option(for status: String, section: CallSection) -> StatusOption {
        let options = section == .incoming ? incoming : outgoing
        return options.first(where: { $0.value == status }) ?? .init(value: status, title: status, colorKey: .neutral)
    }
}

final class APIClient {
    private let baseURL: URL
    private let token: String
    private let session: URLSession

    init(baseURL: URL, token: String) {
        self.baseURL = APIClient.apiURL(from: baseURL)
        self.token = token
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 25
        config.timeoutIntervalForResource = 180
        self.session = URLSession(configuration: config)
    }

    static func apiURL(from url: URL) -> URL {
        if url.lastPathComponent.lowercased() == "api.php" { return url }
        return url.appendingPathComponent("api.php")
    }

    private func jsonRequest(url: URL, method: String = "GET", body: Data? = nil) async throws -> [String: Any] {
        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if body != nil { request.setValue("application/json", forHTTPHeaderField: "Content-Type") }
        request.httpBody = body
        if !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:]
        guard (200...299).contains(http.statusCode) else {
            if http.statusCode == 401 { throw APIError.unauthorized }
            throw APIError.server(json["error"] as? String ?? "HTTP \(http.statusCode)")
        }
        guard json["ok"] as? Bool == true else { throw APIError.server(json["error"] as? String ?? "Некорректный ответ") }
        return json
    }

    func registerPush(installationID: String, fcmToken: String) async throws {
        var parts = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        parts.queryItems = [URLQueryItem(name: "action", value: "register_push")]
        guard let url = parts.url else { throw APIError.invalidURL }
        let body = try JSONSerialization.data(withJSONObject: [
            "installation_id": installationID,
            "platform": "ios",
            "fcm_token": fcmToken
        ])
        _ = try await jsonRequest(url: url, method: "POST", body: body)
    }

    func mobileName() async throws -> String {
        var parts = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        parts.queryItems = [URLQueryItem(name: "action", value: "config")]
        guard let url = parts.url else { throw APIError.invalidURL }
        let json = try await jsonRequest(url: url)
        let value = (json["mobile_name"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty ? "DashBoard" : value
    }

    func agents() async throws -> [CallAgent] {
        var parts = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        parts.queryItems = [URLQueryItem(name: "action", value: "agents")]
        guard let url = parts.url else { throw APIError.invalidURL }
        let json = try await jsonRequest(url: url)
        if let rows = json["agents"] as? [[String: Any]] {
            return rows.compactMap { row in
                guard let number = row["number"] as? String else { return nil }
                return CallAgent(number: number, name: row["name"] as? String ?? number)
            }
        }
        if let map = json["agents"] as? [String: String] {
            return map.map { CallAgent(number: $0.key, name: $0.value) }.sorted { $0.number < $1.number }
        }
        throw APIError.invalidPayload
    }

    func originate(agent: String, client: String) async throws {
        var parts = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)!
        parts.path = (parts.path as NSString).deletingLastPathComponent + "/call.php"
        parts.queryItems = nil
        guard let url = parts.url else { throw APIError.invalidURL }
        let body = try JSONSerialization.data(withJSONObject: ["from": agent, "to": client])
        _ = try await jsonRequest(url: url, method: "POST", body: body)
    }

    func fetch(section: CallSection, from: Date, to: Date, status: String?, offset: Int, limit: Int = 100) async throws -> APIPage {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)
        var items = components?.queryItems ?? []
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone.current
        formatter.dateFormat = "yyyy-MM-dd"
        items.removeAll { ["type", "date_from", "date_to", "status", "limit", "offset"].contains($0.name) }
        items += [
            URLQueryItem(name: "type", value: section.rawValue),
            URLQueryItem(name: "date_from", value: formatter.string(from: from)),
            URLQueryItem(name: "date_to", value: formatter.string(from: to)),
            URLQueryItem(name: "limit", value: String(limit)),
            URLQueryItem(name: "offset", value: String(offset))
        ]
        if let status, !status.isEmpty { items.append(URLQueryItem(name: "status", value: status)) }
        components?.queryItems = items
        guard let url = components?.url else { throw APIError.invalidURL }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        guard (200...299).contains(http.statusCode) else {
            let message = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["error"] as? String
            if http.statusCode == 401 { throw APIError.unauthorized }
            throw APIError.server(message ?? "HTTP \(http.statusCode)")
        }
        guard let object = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              object["ok"] as? Bool == true,
              let rawCalls = object["calls"] as? [[String: Any]] else {
            throw APIError.invalidPayload
        }
        let calls = rawCalls.map { CallRecord(json: $0, type: section) }
        let pagination = object["pagination"] as? [String: Any] ?? [:]
        let total = (pagination["total"] as? NSNumber)?.intValue ?? calls.count
        let kpi = object["kpi"] as? [String: Any] ?? [:]
        return APIPage(calls: calls, total: total, kpi: kpi)
    }
    func downloadRecording(for call: CallRecord, section: CallSection) async throws -> URL {
        var components = URLComponents(url: baseURL, resolvingAgainstBaseURL: false)
        guard components != nil else { throw APIError.invalidURL }
        let directory = (components!.path as NSString).deletingLastPathComponent
        let normalizedDirectory = directory == "/" ? "" : directory
        components!.path = normalizedDirectory + "/recordings.php"
        var query: [URLQueryItem] = []
        if section == .incoming {
            guard !call.callID.isEmpty else { throw APIError.recordingUnavailable }
            query.append(URLQueryItem(name: "uniqueid", value: call.callID))
        } else {
            guard let outgoingID = Int(call.id), outgoingID > 0 else { throw APIError.recordingUnavailable }
            query.append(URLQueryItem(name: "outgoing_id", value: String(outgoingID)))
        }
        components!.queryItems = query
        guard let url = components!.url else { throw APIError.invalidURL }

        var request = URLRequest(url: url)
        request.httpMethod = "GET"
        request.setValue("audio/mpeg, audio/*;q=0.9, */*;q=0.8", forHTTPHeaderField: "Accept")
        if !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        let (temporaryURL, response) = try await session.download(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        guard (200...299).contains(http.statusCode) else {
            let data = (try? Data(contentsOf: temporaryURL)) ?? Data()
            let message = String(data: data, encoding: .utf8) ?? "HTTP \(http.statusCode)"
            throw APIError.server(message)
        }
        let destination = FileManager.default.temporaryDirectory
            .appendingPathComponent("qstat-recording-\(UUID().uuidString).mp3")
        try FileManager.default.moveItem(at: temporaryURL, to: destination)
        return destination
    }


}

enum APIError: LocalizedError {
    case invalidURL, invalidResponse, invalidPayload, unauthorized, recordingUnavailable, server(String)
    var errorDescription: String? {
        switch self {
        case .invalidURL: return "Проверьте адрес API."
        case .invalidResponse: return "Сервер вернул некорректный ответ."
        case .invalidPayload: return "Не удалось разобрать JSON. Проверьте, что установлен совместимый api.php."
        case .unauthorized: return "Ошибка авторизации. Проверьте токен API."
        case .recordingUnavailable: return "Для этого звонка нет идентификатора записи."
        case .server(let message): return "Ошибка API: \(message)"
        }
    }
}
