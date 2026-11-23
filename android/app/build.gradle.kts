import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.ksp)
    // Firebase يُفعَّل فقط عند وجود google-services.json (لا يُحفظ في المستودع)
    id("com.google.gms.google-services") version "4.4.2" apply false
}

val hasFirebase = file("google-services.json").exists()
if (hasFirebase) apply(plugin = "com.google.gms.google-services")

android {
    namespace = "com.pef.fleet"
    compileSdk = 35
    defaultConfig {
        applicationId = "com.pef.fleet.driver"
        minSdk = 26
        targetSdk = 35
        versionCode = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()
        versionName = "0.1.0"
        buildConfigField("boolean", "PUSH_ENABLED", hasFirebase.toString())
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"${System.getenv("FLEET_DEFAULT_SERVER_URL") ?: ""}\"")
    }
    signingConfigs {
        // توقيع الإنتاج: متغيرات بيئة/أسرار GitHub فقط. بدونها لا يُنتج إصدار موقّع.
        val ks = System.getenv("RELEASE_KEYSTORE_PATH")
        if (ks != null) create("release") {
            storeFile = file(ks)
            storePassword = System.getenv("RELEASE_KEYSTORE_PASSWORD")
            keyAlias = System.getenv("RELEASE_KEY_ALIAS")
            keyPassword = System.getenv("RELEASE_KEY_PASSWORD")
        }
    }
    buildTypes {
        debug { applicationIdSuffix = ".debug"; versionNameSuffix = "-debug" }
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfigs.findByName("release")?.let { signingConfig = it }
        }
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures { compose = true; buildConfig = true }
    packaging { resources.excludes += "/META-INF/{AL2.0,LGPL2.1}" }
    testOptions { unitTests.isReturnDefaultValues = true }
}

dependencies {
    implementation(project(":core"))
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui); implementation(libs.compose.material3); implementation(libs.compose.icons)
    implementation(libs.compose.tooling.preview)
    implementation(libs.activity.compose); implementation(libs.lifecycle.runtime); implementation(libs.lifecycle.viewmodel)
    implementation(libs.navigation.compose); implementation(libs.core.ktx)
    implementation(libs.room.runtime); implementation(libs.room.ktx); ksp(libs.room.compiler)
    implementation(libs.work.runtime); implementation(libs.okhttp); implementation(libs.coroutines.android)
    implementation(libs.play.location); implementation(libs.security.crypto); implementation(libs.osmdroid)
    implementation(platform(libs.firebase.bom)); implementation(libs.firebase.messaging)
    testImplementation(libs.junit); testImplementation(libs.okhttp.mockwebserver); testImplementation(libs.coroutines.test)
    testImplementation(kotlin("test"))
}
