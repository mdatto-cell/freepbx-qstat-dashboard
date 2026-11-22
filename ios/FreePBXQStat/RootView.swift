import SwiftUI
import AVKit
import AVFoundation

final class AppSettings: ObservableObject {
    @Published var apiAddress: String = UserDefaults.standard.string(forKey: "apiAddress") ?? ""
    @Published var token: String = KeychainStore.readToken() ?? ""
    @Published var mobileName: String = "DashBoard"
    @Published var theme: String = UserDefaults.standard.string(forKey: "theme") ?? "system" {
        didSet { UserDefaults.standard.set(theme, forKey: "theme") }
    }
    var colorScheme: ColorScheme? { theme == "dark" ? .dark : (theme == "light" ? .light : nil) }
    @Published var connected: Bool = UserDefaults.standard.bool(forKey: "isConnected")

    func save(address: String, token: String) throws {
        let trimmed = address.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let url = URL(string: trimmed), let scheme = url.scheme?.lowercased(),
              ["http", "https"].contains(scheme), url.host != nil else {
            throw APIError.invalidURL
        }
        try KeychainStore.save(token: token)
        apiAddress = APIClient.apiURL(from: url).absoluteString
        self.token = token
        connected = true
        UserDefaults.standard.set(apiAddress, forKey: "apiAddress")
        UserDefaults.standard.set(true, forKey: "isConnected")
    }

    func disconnect() {
        connected = false
        token = ""
        KeychainStore.deleteToken()
        UserDefaults.standard.set(false, forKey: "isConnected")
    }
}

struct RootView: View {
    @StateObject private var settings = AppSettings()
    @StateObject private var pushNavigation = PushNavigation.shared

    var body: some View {
        Group {
            if settings.connected {
                DashboardView(settings: settings)
            } else {
                LoginView(settings: settings)
            }
        }
        .tint(Color(red: 0.0, green: 0.41, blue: 0.44))
        .preferredColorScheme(settings.colorScheme)
        .environmentObject(pushNavigation)
        .onAppear { if settings.connected { PushRegistration.shared.activate() } }
        .onChange(of: settings.connected) { connected in
            if connected { PushRegistration.shared.activate() }
        }
    }
}

struct LoginView: View {
    @ObservedObject var settings: AppSettings
    @State private var address: String
    @State private var token: String
    @State private var isConnecting = false
    @State private var errorMessage: String?

    init(settings: AppSettings) {
        self.settings = settings
        _address = State(initialValue: settings.apiAddress)
        _token = State(initialValue: settings.token)
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 26) {
                Spacer()
                Image(systemName: "chart.bar.xaxis")
                    .font(.system(size: 48, weight: .semibold))
                    .foregroundStyle(Color(red: 0.0, green: 0.41, blue: 0.44))
                    .frame(width: 94, height: 94)
                    .background(Color(red: 0.89, green: 0.95, blue: 0.94), in: RoundedRectangle(cornerRadius: 26))
                VStack(spacing: 7) {
                    Text("DashBoard")
                        .font(.largeTitle.bold())
                    Text("Статистика телефонных звонков")
                        .foregroundStyle(.secondary)
                }
                VStack(alignment: .leading, spacing: 16) {
                    VStack(alignment: .leading, spacing: 7) {
                        Text("Адрес API").font(.subheadline.weight(.semibold))
                        TextField("https://pbx.example.ru/qstat", text: $address)
                            .keyboardType(.URL)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .textFieldStyle(.roundedBorder)
                    }
                    VStack(alignment: .leading, spacing: 7) {
                        Text("Токен").font(.subheadline.weight(.semibold))
                        SecureField("Bearer token (если авторизация включена)", text: $token)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .textFieldStyle(.roundedBorder)
                    }
                    Text("Укажите адрес каталога qstat (без api.php). Если авторизация API отключена на сервере, поле токена можно оставить пустым.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                if let errorMessage {
                    Label(errorMessage, systemImage: "exclamationmark.triangle.fill")
                        .font(.footnote)
                        .foregroundStyle(.red)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                Button {
                    Task { await connect() }
                } label: {
                    HStack {
                        if isConnecting { ProgressView().tint(.white) }
                        Text(isConnecting ? "Проверка подключения…" : "Подключиться")
                            .fontWeight(.semibold)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 15)
                    .foregroundStyle(.white)
                    .background(Color(red: 0.0, green: 0.41, blue: 0.44), in: RoundedRectangle(cornerRadius: 14))
                }
                .disabled(isConnecting || address.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                Spacer()
                Text("Токен хранится в защищённом хранилище iOS Keychain.")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
            .padding(24)
            .background(Color(uiColor: .systemGroupedBackground))
            .navigationBarHidden(true)
        }
    }

    @MainActor private func connect() async {
        errorMessage = nil
        guard let url = URL(string: address.trimmingCharacters(in: .whitespacesAndNewlines)),
              let scheme = url.scheme?.lowercased(), ["http", "https"].contains(scheme), url.host != nil else {
            errorMessage = APIError.invalidURL.localizedDescription
            return
        }
        isConnecting = true
        defer { isConnecting = false }
        do {
            let client = APIClient(baseURL: url, token: token)
            let today = Calendar.current.startOfDay(for: Date())
            _ = try await client.fetch(section: .incoming, from: today, to: today, status: nil, offset: 0, limit: 1)
            try settings.save(address: address, token: token)
            settings.mobileName = (try? await client.mobileName()) ?? "DashBoard"
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

struct DashboardView: View {
    @ObservedObject var settings: AppSettings
    @EnvironmentObject private var pushNavigation: PushNavigation
    @State private var section: CallSection = .incoming
    @State private var selectedDate = Calendar.current.startOfDay(for: Date())
    @State private var selectedStatus = ""
    @State private var calls: [CallRecord] = []
    @State private var total = 0
    @State private var kpi: [String: Any] = [:]
    @State private var isLoading = false
    @State private var isLoadingMore = false
    @State private var errorMessage: String?
    @State private var showingDatePicker = false
    @State private var showingSettings = false
    @State private var recordingPlayback: RecordingPlayback?
    @State private var recordingLoadingID: String?
    @State private var recordingError: String?
    @State private var calling: CallRecord?
    @State private var agents: [CallAgent] = []
    @State private var callError: String?
    @State private var activeRequestID = UUID()
    private let pageSize = 100

    private var statuses: [StatusOption] { section == .incoming ? CallStatuses.incoming : CallStatuses.outgoing }
    private var nextOffset: Int { calls.count }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                Picker("Тип звонков", selection: $section) {
                    ForEach(CallSection.allCases) { item in
                        Label(item.title, systemImage: item.icon).tag(item)
                    }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.top, 10)
                statusSelector
                kpiSummary
                contentList
            }
            .background(Color(uiColor: .systemGroupedBackground))
            .navigationTitle(settings.mobileName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    Button { showingDatePicker = true } label: {
                        Image(systemName: "calendar")
                            .accessibilityLabel("Выбрать дату")
                    }
                    Button { showingSettings = true } label: { Image(systemName: "gearshape") }
                }
            }
            .sheet(isPresented: $showingDatePicker) {
                SingleDateSheet(date: $selectedDate) {
                    showingDatePicker = false
                    Task { await reload() }
                }
                .presentationDetents([.medium, .large])
            }
            .sheet(item: $recordingPlayback) { playback in
                RecordingPlayerSheet(playback: playback)
                    .presentationDetents([.medium])
            }
            .alert("Не удалось открыть запись", isPresented: Binding(get: { recordingError != nil }, set: { if !$0 { recordingError = nil } })) {
                Button("OK", role: .cancel) { recordingError = nil }
            } message: { Text(recordingError ?? "") }
            .sheet(isPresented: $showingSettings) {
                SettingsSheet(settings: settings, onDisconnect: {
                    settings.disconnect()
                })
                .presentationDetents([.medium])
            }
            .sheet(item: $calling) { call in
                CallAgentSheet(call: call, agents: agents, settings: settings) { calling = nil }
                    .presentationDetents([.medium, .large])
            }
            .task {
                await reload()
                await refreshConfiguration()
            }
            .onReceive(pushNavigation.$missedCallRequest) { request in
                guard request > 0 else { return }
                section = .incoming
                selectedStatus = "missed_no_callback"
                selectedDate = Calendar.current.startOfDay(for: Date())
                Task { await reload() }
            }
            .onChange(of: section) { _ in
                // Preserve the push-selected missed filter on incoming navigation.
                if selectedStatus != "missed_no_callback" || section != .incoming {
                    selectedStatus = ""
                }
                Task { await reload() }
            }
        }
    }

    private var statusSelector: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                statusChip(title: "Все", value: "", color: .neutral)
                ForEach(statuses) { option in
                    statusChip(title: option.title, value: option.value, color: option.colorKey)
                }
            }
            .padding(.horizontal)
        }
        .frame(height: 48)
        .padding(.top, 6)
        .padding(.bottom, 4)
        .background(Color(uiColor: .systemGroupedBackground))
    }

    private func statusChip(title: String, value: String, color: StatusColor) -> some View {
        let selected = selectedStatus == value
        return Button {
            guard selectedStatus != value else { return }
            selectedStatus = value
            Task { await reload() }
        } label: {
            Text(title)
                .font(.caption.weight(.semibold))
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .foregroundStyle(selected ? Color.white : palette(color).foreground)
                .background(selected ? Color(red: 0.0, green: 0.41, blue: 0.44) : palette(color).background, in: Capsule())
                .overlay(Capsule().stroke(selected ? Color.clear : palette(color).foreground.opacity(0.16), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    private var kpiSummary: some View {
        HStack(spacing: 10) {
            if selectedStatus.isEmpty {
                // Общий обзор: две ключевые метрики для каждого направления.
                kpiTile("Всего", value: kpiValue("total_calls"), color: .neutral)
                if section == .incoming {
                    kpiTile("Пропущенные", value: kpiValue("missed_no_callback"), color: .danger)
                } else {
                    // API считает NO ANSWER и BUSY одной группой «не дозвонились».
                    kpiTile("Не дозвонились", value: kpiValue("noanswer"), color: .warning)
                }
            } else {
                // При выбранном статусе оставляем одну плитку с числом
                // звонков в текущей выборке и цветом выбранного статуса.
                let selectedColor = statuses.first(where: { $0.value == selectedStatus })?.colorKey ?? .neutral
                kpiTile("Всего", value: String(total), color: selectedColor)
            }
        }
        .padding(.horizontal)
        .padding(.bottom, 10)
    }

    private func kpiTile(_ title: String, value: String, color: StatusColor) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(title).font(.caption2).foregroundStyle(.secondary).lineLimit(1)
            Text(value).font(.title3.bold()).foregroundStyle(palette(color).foreground)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(11)
        .background(palette(color).background, in: RoundedRectangle(cornerRadius: 12))
    }

    private var contentList: some View {
        List {
            if isLoading && calls.isEmpty {
                HStack { Spacer(); ProgressView("Загружаем звонки…"); Spacer() }
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            } else if let errorMessage, calls.isEmpty {
                ContentUnavailableView {
                    Label("Не удалось загрузить данные", systemImage: "wifi.exclamationmark")
                } description: {
                    Text(errorMessage)
                } actions: {
                    Button("Повторить") { Task { await reload() } }.buttonStyle(.borderedProminent)
                }
                .listRowSeparator(.hidden)
                .listRowBackground(Color.clear)
            } else if calls.isEmpty {
                ContentUnavailableView("Звонков нет", systemImage: "phone.down", description: Text("За выбранную дату звонки не найдены."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            } else {
                Section {
                    ForEach(calls) { call in
                        CallCard(call: call, section: section, isLoadingRecording: recordingLoadingID == call.id,
                                 onPlayRecording: { Task { await playRecording(for: call) } },
                                 onCall: { calling = call })
                        .listRowInsets(EdgeInsets(top: 7, leading: 14, bottom: 7, trailing: 14))
                        .listRowSeparator(.hidden)
                        .listRowBackground(Color.clear)
                    }
                    if calls.count < total {
                        HStack {
                            Spacer()
                            if isLoadingMore { ProgressView() }
                            else { Button("Загрузить ещё") { Task { await loadMore() } } }
                            Spacer()
                        }
                        .listRowSeparator(.hidden)
                        .listRowBackground(Color.clear)
                    }
                } header: {
                    HStack {
                        Text(section == .incoming ? "Журнал входящих" : "Журнал исходящих")
                        Spacer()
                        Text("\(calls.count) из \(total)").textCase(nil)
                    }
                }
            }
        }
        .listStyle(.plain)
        .refreshable { await reload() }
        .alert("Ошибка загрузки", isPresented: Binding(get: { errorMessage != nil && !calls.isEmpty }, set: { if !$0 { errorMessage = nil } })) {
            Button("OK", role: .cancel) { errorMessage = nil }
        } message: { Text(errorMessage ?? "") }
    }

    private func kpiValue(_ key: String) -> String {
        guard let value = kpi[key] else { return "0" }
        return String(describing: value)
    }

    @MainActor private func reload() async {
        activeRequestID = UUID()
        let requestID = activeRequestID
        calls = []
        total = 0
        kpi = [:]
        errorMessage = nil
        await fetchPage(offset: 0, append: false, requestID: requestID)
    }

    @MainActor private func loadMore() async {
        guard !isLoadingMore, calls.count < total else { return }
        await fetchPage(offset: nextOffset, append: true, requestID: activeRequestID)
    }

    @MainActor private func fetchPage(offset: Int, append: Bool, requestID: UUID) async {
        guard let url = URL(string: settings.apiAddress) else {
            if requestID == activeRequestID { errorMessage = APIError.invalidURL.localizedDescription }
            return
        }
        if append { isLoadingMore = true } else { isLoading = true }
        defer {
            if requestID == activeRequestID {
                isLoading = false
                isLoadingMore = false
            }
        }
        do {
            let client = APIClient(baseURL: url, token: settings.token)
            let day = Calendar.current.startOfDay(for: selectedDate)
            let page = try await client.fetch(section: section, from: day, to: day,
                                             status: selectedStatus.isEmpty ? nil : selectedStatus,
                                             offset: offset, limit: pageSize)
            guard requestID == activeRequestID, !Task.isCancelled else { return }
            if append { calls.append(contentsOf: page.calls) } else { calls = page.calls }
            total = page.total
            kpi = page.kpi
            errorMessage = nil
        } catch {
            // Pull-to-refresh and rapid filter changes can cancel an in-flight URLSession request.
            // Cancellation is expected and must not be presented as a network failure.
            if error is CancellationError || (error as? URLError)?.code == .cancelled || Task.isCancelled {
                return
            }
            guard requestID == activeRequestID else { return }
            errorMessage = error.localizedDescription
            if let apiError = error as? APIError, case .unauthorized = apiError { settings.disconnect() }
        }
    }

    @MainActor private func refreshConfiguration() async {
        guard let url = URL(string: settings.apiAddress) else { return }
        let client = APIClient(baseURL: url, token: settings.token)
        if let title = try? await client.mobileName() { settings.mobileName = title }
        if let available = try? await client.agents() { agents = available }
    }

    @MainActor private func playRecording(for call: CallRecord) async {
        guard call.hasRecording else { return }
        guard let url = URL(string: settings.apiAddress) else { recordingError = APIError.invalidURL.localizedDescription; return }
        recordingLoadingID = call.id
        defer { recordingLoadingID = nil }
        do {
            let client = APIClient(baseURL: url, token: settings.token)
            let localURL = try await client.downloadRecording(for: call, section: section)
            recordingPlayback = RecordingPlayback(url: localURL, title: "Запись звонка · \(call.formattedTime)")
        } catch {
            recordingError = error.localizedDescription
        }
    }
}

struct CallCard: View {
    let call: CallRecord
    let section: CallSection
    let isLoadingRecording: Bool
    let onPlayRecording: () -> Void
    let onCall: () -> Void
    private var statusOption: StatusOption { CallStatuses.option(for: call.status, section: section) }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .center, spacing: 8) {
                Text(call.formattedTime).font(.subheadline.weight(.semibold))
                Spacer(minLength: 4)
                Text(statusOption.title)
                    .font(.caption2.weight(.bold))
                    .padding(.horizontal, 8).padding(.vertical, 5)
                    .foregroundStyle(palette(statusOption.colorKey).foreground)
                    .background(palette(statusOption.colorKey).background, in: Capsule())
                if !call.clientNumber.isEmpty {
                    Button(action: onCall) { Image(systemName: "phone.arrow.up.right").font(.title3) }
                        .buttonStyle(.plain).accessibilityLabel("Позвонить клиенту")
                }
                if call.hasRecording {
                    Button(action: onPlayRecording) {
                        Group {
                            if isLoadingRecording { ProgressView().controlSize(.small) }
                            else { Image(systemName: "play.circle.fill").font(.title2) }
                        }
                    }
                    .buttonStyle(.plain)
                    .disabled(isLoadingRecording)
                    .accessibilityLabel("Прослушать запись звонка")
                }
            }
            if section == .incoming {
                VStack(alignment: .leading, spacing: 2) {
                    Text("КЛИЕНТ").font(.caption2).foregroundStyle(.secondary)
                    Button(action: onCall) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(call.clientName.isEmpty ? call.clientNumber : call.clientName)
                                .font(.headline.weight(.semibold)).lineLimit(1)
                            if !call.clientName.isEmpty && !call.clientNumber.isEmpty {
                                Text(call.clientNumber).font(.caption.monospaced()).foregroundStyle(.secondary)
                            }
                        }
                    }.buttonStyle(.plain).disabled(call.clientNumber.isEmpty)
                }
                HStack(spacing: 12) {
                    compactDetail("Очередь", value: call.queueLabel.isEmpty ? call.queueNumber : call.queueLabel)
                    if call.status != "missed_no_callback" { compactDetail("Агент", value: call.agent.isEmpty ? "—" : call.agent) }
                }
                HStack(spacing: 5) {
                    Image(systemName: "arrow.down.left.circle")
                    Text(call.didLabel.isEmpty ? call.didNumber : call.didLabel)
                    if !call.didNumber.isEmpty && call.didLabel != call.didNumber {
                        Text("· \(call.didNumber)")
                    }
                }
                .font(.caption2).foregroundStyle(.tertiary).lineLimit(1)
            } else {
                VStack(alignment: .leading, spacing: 2) {
                    Text("КЛИЕНТ").font(.caption2).foregroundStyle(.secondary)
                    Button(action: onCall) {
                        Text(call.clientNumber.isEmpty ? "—" : call.clientNumber)
                            .font(.headline.weight(.semibold)).lineLimit(1)
                    }.buttonStyle(.plain).disabled(call.clientNumber.isEmpty)
                }
                HStack(spacing: 12) {
                    compactDetail("Агент", value: call.agent.isEmpty ? "—" : call.agent)
                    compactDetail("Исходящий номер", value: call.didNumber.isEmpty ? "—" : call.didNumber)
                }
            }
            HStack(spacing: 16) {
                Label("Ожидание \(call.duration(call.waitSeconds))", systemImage: "hourglass")
                Label("Разговор \(call.duration(call.talkSeconds))", systemImage: "clock")
            }
            .font(.caption2.monospacedDigit()).foregroundStyle(.secondary)
        }
        .padding(10)
        .background(palette(statusOption.colorKey).rowBackground, in: RoundedRectangle(cornerRadius: 13))
        .overlay(RoundedRectangle(cornerRadius: 13).stroke(palette(statusOption.colorKey).foreground.opacity(0.12), lineWidth: 1))
    }

    private func compactDetail(_ title: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(title.uppercased()).font(.caption2).foregroundStyle(.secondary)
            Text(value.isEmpty ? "—" : value).font(.caption.weight(.medium)).lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct SingleDateSheet: View {
    @Binding var date: Date
    let onApply: () -> Void
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            VStack(spacing: 12) {
                DatePicker("Дата звонков", selection: $date, displayedComponents: .date)
                    .datePickerStyle(.graphical)
                    .padding(.horizontal, 8)
                Spacer(minLength: 0)
            }
            .padding(.top, 12)
            .navigationTitle("Выбор даты")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Отмена") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) { Button("Показать") { onApply() } }
            }
        }
    }
}

struct RecordingPlayback: Identifiable {
    let id = UUID()
    let url: URL
    let title: String
}

struct RecordingPlayerSheet: View {
    let playback: RecordingPlayback
    @Environment(\.dismiss) private var dismiss
    @State private var player: AVPlayer
    @State private var currentTime: Double = 0
    @State private var duration: Double = 0
    @State private var isPlaying = false
    @State private var isSeeking = false
    @State private var timeObserver: Any?

    init(playback: RecordingPlayback) {
        self.playback = playback
        _player = State(initialValue: AVPlayer(url: playback.url))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 20) {
                Image(systemName: "waveform")
                    .font(.system(size: 42)).foregroundStyle(.tint)
                Text(playback.title).font(.headline).multilineTextAlignment(.center)

                VStack(spacing: 8) {
                    Slider(value: $currentTime, in: 0...max(duration, 1), onEditingChanged: { editing in
                        isSeeking = editing
                        if !editing {
                            let target = CMTime(seconds: currentTime, preferredTimescale: 600)
                            player.seek(to: target, toleranceBefore: .zero, toleranceAfter: .zero)
                        }
                    })
                    .disabled(duration <= 0)
                    HStack {
                        Text(timeString(currentTime))
                        Spacer()
                        Text(timeString(duration))
                    }
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)
                }

                Button {
                    if isPlaying { player.pause() } else { player.play() }
                    isPlaying.toggle()
                } label: {
                    Label(isPlaying ? "Пауза" : "Воспроизвести", systemImage: isPlaying ? "pause.fill" : "play.fill")
                        .font(.headline)
                        .frame(minWidth: 180)
                        .padding(.vertical, 12)
                }
                .buttonStyle(.borderedProminent)

                Text("Можно перемещать ползунок, чтобы перейти к нужному месту записи.")
                    .font(.footnote).foregroundStyle(.secondary).multilineTextAlignment(.center)
            }
            .padding(24)
            .navigationTitle("Запись звонка")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Готово") { closePlayer() } } }
            .onAppear { configurePlayer() }
            .onDisappear { cleanupPlayer() }
        }
    }

    private func configurePlayer() {
        let interval = CMTime(seconds: 0.25, preferredTimescale: 600)
        timeObserver = player.addPeriodicTimeObserver(forInterval: interval, queue: .main) { time in
            if !isSeeking { currentTime = max(0, time.seconds.isFinite ? time.seconds : 0) }
            if let item = player.currentItem {
                let seconds = item.duration.seconds
                if seconds.isFinite && seconds > 0 { duration = seconds }
            }
        }
        player.play()
        isPlaying = true
    }

    private func closePlayer() {
        player.pause()
        isPlaying = false
        dismiss()
    }

    private func cleanupPlayer() {
        player.pause()
        if let timeObserver { player.removeTimeObserver(timeObserver) }
        timeObserver = nil
        try? FileManager.default.removeItem(at: playback.url)
    }

    private func timeString(_ value: Double) -> String {
        guard value.isFinite && value >= 0 else { return "00:00" }
        let seconds = Int(value.rounded(.down))
        return String(format: "%02d:%02d", seconds / 60, seconds % 60)
    }
}

struct SettingsSheet: View {
    @ObservedObject var settings: AppSettings
    let onDisconnect: () -> Void
    @Environment(\.dismiss) private var dismiss
    var body: some View {
        NavigationStack {
            Form {
                Section("Подключение") {
                    LabeledContent("API", value: settings.apiAddress)
                        .textSelection(.enabled)
                }
                Section("Оформление") {
                    Picker("Тема", selection: $settings.theme) {
                        Text("Системная").tag("system")
                        Text("Светлая").tag("light")
                        Text("Тёмная").tag("dark")
                    }
                }
                Section {
                    Button("Отключить и удалить токен", role: .destructive) {
                        onDisconnect()
                        dismiss()
                    }
                }
            }
            .navigationTitle("Настройки")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Готово") { dismiss() } } }
        }
    }
}

private func palette(_ key: StatusColor) -> (foreground: Color, background: Color, rowBackground: Color) {
    switch key {
    case .danger:
        return (Color(red: 0.81, green: 0.25, blue: 0.27), Color(red: 0.99, green: 0.91, blue: 0.92), Color(red: 0.99, green: 0.94, blue: 0.94))
    case .success:
        return (Color(red: 0.26, green: 0.48, blue: 0.13), Color(red: 0.91, green: 0.95, blue: 0.88), Color(red: 0.95, green: 0.97, blue: 0.92))
    case .warning:
        return (Color(red: 0.69, green: 0.48, blue: 0.0), Color(red: 1.0, green: 0.95, blue: 0.81), Color(red: 1.0, green: 0.97, blue: 0.88))
    case .busy:
        // Похож на «Не ответили», но отличается более тёплым коралловым оттенком.
        return (Color(red: 0.76, green: 0.34, blue: 0.22), Color(red: 1.0, green: 0.91, blue: 0.87), Color(red: 1.0, green: 0.95, blue: 0.92))
    case .info:
        return (Color(red: 0.18, green: 0.41, blue: 0.77), Color(red: 0.89, green: 0.93, blue: 1.0), Color(red: 0.94, green: 0.96, blue: 1.0))
    case .nightCall:
        return (Color(red: 0.72, green: 0.19, blue: 0.48), Color(red: 0.98, green: 0.81, blue: 0.90), Color(red: 1.0, green: 0.92, blue: 0.96))
    case .nightSelf:
        return (Color(red: 0.43, green: 0.29, blue: 0.77), Color(red: 0.87, green: 0.82, blue: 1.0), Color(red: 0.95, green: 0.93, blue: 1.0))
    case .neutral:
        return (Color(red: 0.25, green: 0.25, blue: 0.23), Color(uiColor: .secondarySystemGroupedBackground), Color(uiColor: .secondarySystemGroupedBackground))
    }
}


struct CallAgentSheet: View {
    let call: CallRecord
    let agents: [CallAgent]
    @ObservedObject var settings: AppSettings
    let onSuccess: () -> Void
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            List {
                Section("Клиент") {
                    Text(call.clientName.isEmpty ? call.clientNumber : call.clientName)
                    if !call.clientName.isEmpty { Text(call.clientNumber).foregroundStyle(.secondary) }
                }
                Section("Позвонить через агента") {
                    if agents.isEmpty { Text("Список агентов недоступен").foregroundStyle(.secondary) }
                    ForEach(agents) { agent in
                        Button {
                            Task { await startCall(agent.number) }
                        } label: {
                            HStack {
                                Image(systemName: "phone.fill")
                                Text(agent.name)
                                Spacer()
                                Text(agent.number).foregroundStyle(.secondary)
                            }
                        }.disabled(busy)
                    }
                }
                if let error { Text(error).foregroundStyle(.red) }
            }
            .navigationTitle("Позвонить клиенту")
            .navigationBarTitleDisplayMode(.inline)
            .overlay { if busy { ProgressView() } }
        }
    }

    @MainActor private func startCall(_ number: String) async {
        guard let url = URL(string: settings.apiAddress) else { error = "Некорректный адрес"; return }
        busy = true
        defer { busy = false }
        do {
            try await APIClient(baseURL: url, token: settings.token).originate(agent: number, client: call.clientNumber)
            onSuccess()
        } catch { self.error = error.localizedDescription }
    }
}
