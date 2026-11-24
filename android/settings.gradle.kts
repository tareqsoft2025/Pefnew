pluginManagement {
    repositories { google(); mavenCentral(); gradlePluginPortal() }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories { google(); mavenCentral() }
}
rootProject.name = "FleetDriver"
include(":core")
// وحدة التطبيق تتطلب Android SDK؛ تُستبعد عند تعيين -PcoreOnly=true (مفيد لاختبار المنطق على JVM فقط)
if (providers.gradleProperty("coreOnly").orNull != "true") { include(":app") }
